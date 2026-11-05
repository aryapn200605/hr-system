<?php

namespace App\Services\Requests;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Exceptions\DecisionRefusedException;
use App\Exceptions\InsufficientLeaveBalanceException;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\LeaveBalance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Approving and rejecting requests (DECISIONS.md sections 3, 5 and 6), the authoritative second layer
 * of the lock: one transaction that locks, reads again and checks everything.
 *
 * Lock order, the same for every decision (README "Approvals"):
 *  1. the HR Admin role row, shared (User::shareAccountLock()): roles, statuses and supervisor links
 *     change only under its exclusive lock, so who may decide is fixed while the decision runs;
 *  2. the request row;
 *  3. the requester's user row, before any plain read (see decide());
 *  4. the leave periods to charge, by start date.
 * A cancellation takes the role row shared and then the request row (its update also checks the user row
 * through the foreign key), a submission and the leave balance services only the user row (and then
 * periods), and account changes the role row then user rows (a suspension then reads the user's pending
 * requests, SuspensionRejections), so no two of them can wait for each other in a circle.
 *
 * Refusals for lack of authority or balance are audited after the rollback. A request already decided
 * or cancelled is not an error: the message says who decided it and when.
 */
class Decisions
{
    public const REJECTION_MIN = 5;

    public const NOTE_MAX = 1000;

    public function __construct(
        private Deciders $deciders,
        private LeaveBalance $balance,
        private RequestNotifications $notifications,
    ) {}

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function approve(User $actor, string $type, mixed $id, mixed $note = null): LeaveRequest|OvertimeRequest
    {
        $validated = Validator::make(['note' => is_string($note) ? trim($note) : $note], [
            'note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
        ], [], ['note' => __('note')])->validate();

        return $this->decide($actor, $type, $id, RequestStatus::Approved, ($validated['note'] ?? '') === '' ? null : $validated['note']);
    }

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function reject(User $actor, string $type, mixed $id, mixed $reason): LeaveRequest|OvertimeRequest
    {
        $validated = Validator::make(['reason' => is_string($reason) ? trim($reason) : $reason], [
            'reason' => ['required', 'string', 'min:'.self::REJECTION_MIN, 'max:'.self::NOTE_MAX],
        ], [], ['reason' => __('reason')])->validate();

        return $this->decide($actor, $type, $id, RequestStatus::Rejected, $validated['reason']);
    }

    /**
     * Why a request can no longer be decided, for anyone who looks at it, or null while it is pending.
     */
    public function outcome(LeaveRequest|OvertimeRequest $record): ?string
    {
        return match ($record->status) {
            RequestStatus::Pending => null,
            RequestStatus::Cancelled => __('The requester cancelled this request on :time.', [
                'time' => $this->time($record->cancelled_at),
            ]),
            default => __('This request was already decided: :status by :name on :time.', [
                'status' => $record->status->label(),
                'name' => $record->deciderLabel(),
                'time' => $this->time($record->decided_at),
            ]),
        };
    }

    /**
     * @return class-string<LeaveRequest|OvertimeRequest>
     */
    public static function model(string $type): string
    {
        return match ($type) {
            'leave' => LeaveRequest::class,
            'overtime' => OvertimeRequest::class,
            default => abort(404),
        };
    }

    /**
     * @throws AuthorizationException|ValidationException
     */
    private function decide(User $actor, string $type, mixed $id, RequestStatus $status, ?string $note): LeaveRequest|OvertimeRequest
    {
        $model = self::model($type);
        // A forged ID such as "1abc" becomes false, which finds nothing (404) instead of MySQL's cast to 1.
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        try {
            $decided = DB::transaction(function () use ($actor, $model, $id, $status, $note) {
                User::shareAccountLock();

                $record = $model::query()->lockForUpdate()->find($id) ?? abort(404);

                // The requester's row next, before any plain read. MySQL's REPEATABLE READ fixes a transaction's
                // snapshot at its first plain read; taking it after this lock makes every balance change of this
                // user that committed before visible (each holds this lock until it commits). Found by the
                // mutation check: with the snapshot taken earlier, two approvals could spend the same days.
                $requester = User::query()->lockForUpdate()->findOrFail($record->user_id)->load('roles');

                // Who may decide is read again now, never taken from the state at submission or from the page.
                $decider = User::query()->with('roles')->findOrFail($actor->getKey());

                if (! $this->deciders->allows($decider, $requester)) {
                    throw new DecisionRefusedException(DecisionRefusedException::NO_AUTHORITY, $record, __('You cannot decide this request.'));
                }

                if (($outcome = $this->outcome($record)) !== null) {
                    throw ValidationException::withMessages(['decision' => $outcome]);
                }

                $holder = ReviewLockHolder::of($record);

                if ($holder !== null && ! $holder->isHeldBy($decider->getKey())) {
                    throw ValidationException::withMessages(['decision' => $this->heldMessage($holder)]);
                }

                $metadata = ['requester_id' => $requester->getKey(), 'note_given' => $note !== null];

                if ($status === RequestStatus::Approved && $record instanceof LeaveRequest && $record->deducts_balance) {
                    try {
                        $metadata['charged'] = $this->balance->chargeApproved($requester, $record, $decider);
                    } catch (InsufficientLeaveBalanceException $exception) {
                        throw new DecisionRefusedException(DecisionRefusedException::INSUFFICIENT_BALANCE, $record, $exception->getMessage());
                    }
                }

                $record->status = $status;
                $record->decided_by = $decider->getKey();
                $record->decided_at = now();
                $record->decision_note = $note;
                $record->review_locked_by = null;
                $record->review_locked_until = null;
                $record->save();

                AuditLog::record($this->event($record, $status), $decider, $record, $metadata);

                return $record;
            });
        } catch (DecisionRefusedException $refusal) {
            AuditLog::record(AuditEvent::RequestDecisionRefused, $actor, $refusal->record, [
                'decision' => $status->value,
                'reason' => $refusal->reason,
            ]);

            if ($refusal->reason === DecisionRefusedException::NO_AUTHORITY) {
                throw new AuthorizationException($refusal->getMessage());
            }

            throw ValidationException::withMessages(['decision' => $refusal->getMessage()]);
        }

        // After the commit, and never able to fail the decision (Phase 13).
        $this->notifications->decided($decided, $actor);

        return $decided;
    }

    /**
     * The view-only message: who reviews the request and until when.
     */
    public function heldMessage(ReviewLockHolder $holder): string
    {
        return __(':name is reviewing this request until :time. You can view it, but not decide it now.', [
            'name' => $holder->name,
            'time' => $holder->until->translatedFormat('H:i T'),
        ]);
    }

    private function event(LeaveRequest|OvertimeRequest $record, RequestStatus $status): AuditEvent
    {
        return match (true) {
            $record instanceof LeaveRequest && $status === RequestStatus::Approved => AuditEvent::LeaveRequestApproved,
            $record instanceof LeaveRequest => AuditEvent::LeaveRequestRejected,
            $status === RequestStatus::Approved => AuditEvent::OvertimeRequestApproved,
            default => AuditEvent::OvertimeRequestRejected,
        };
    }

    private function time(mixed $value): string
    {
        return $value?->translatedFormat('j M Y, H:i T') ?? '';
    }
}
