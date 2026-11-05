<?php

namespace App\Services\Requests;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;

/**
 * Rejecting a user's pending requests when the account is suspended (DECISIONS.md section 11, item 3).
 * Called only by User::suspend(), inside its transaction, so the rejection and the suspension commit
 * or roll back together.
 *
 * Lock order: the HR Admin role row exclusively (User::withinAccountLock()), then the user row, then
 * the user's pending requests are read (pending()). Every writer of a request's status is kept out by
 * one of those two locks, so the requests need no row lock of their own:
 *  - a decision takes the role row shared first (Decisions), so it either commits before the suspension
 *    starts, and its request is no longer pending here, or waits and then finds the request rejected
 *    and says who decided it ("System");
 *  - a cancellation takes the role row shared first too (Submission::cancel()), with the same result;
 *  - a submission locks the user row first (Submission::lockRequester()), so it either commits before
 *    and its request is rejected here, or waits and is then refused because the account is suspended.
 * The real-process races are in ApprovalConcurrencyTest.
 *
 * The status change alone releases a leave request's pending allocations: only pending requests count
 * against a period. Nothing is charged, no email is sent, and reactivation restores nothing.
 */
class SuspensionRejections
{
    /**
     * How many requests of this user are pending now, for the suspension dialog.
     */
    public function pendingCount(User $user): int
    {
        return $this->pendingQuery(LeaveRequest::class, $user)->count()
            + $this->pendingQuery(OvertimeRequest::class, $user)->count();
    }

    /**
     * The user's pending requests, leave first and then overtime, each by ID. Only inside the
     * suspension's transaction, after the user row lock: this is the transaction's first plain read,
     * so its snapshot holds every request committed before the locks were granted.
     *
     * @return array{leave: list<LeaveRequest>, overtime: list<OvertimeRequest>}
     */
    public function pending(User $user): array
    {
        return [
            'leave' => $this->pendingQuery(LeaveRequest::class, $user)->orderBy('id')->get()->all(),
            'overtime' => $this->pendingQuery(OvertimeRequest::class, $user)->orderBy('id')->get()->all(),
        ];
    }

    /**
     * Reject the requests that pending() returned. Returns the counts for the suspension's audit row.
     *
     * @param  array{leave: list<LeaveRequest>, overtime: list<OvertimeRequest>}  $pending
     * @return array{leave: int, overtime: int}
     */
    public function reject(array $pending, ?User $actor): array
    {
        $now = now();

        foreach ([...$pending['leave'], ...$pending['overtime']] as $record) {
            $record->status = RequestStatus::Rejected;
            $record->decided_by = null;
            $record->decided_at = $now;
            $record->decision_note = null;
            $record->system_reason = LeaveRequest::systemReasonSuspended();
            $record->review_locked_by = null;
            $record->review_locked_until = null;
            $record->save();

            AuditLog::record(
                $record instanceof LeaveRequest ? AuditEvent::LeaveRequestRejected : AuditEvent::OvertimeRequestRejected,
                $actor,
                $record,
                ['requester_id' => $record->user_id, 'system_reason' => $record->system_reason],
            );
        }

        return ['leave' => count($pending['leave']), 'overtime' => count($pending['overtime'])];
    }

    /**
     * @param  class-string<LeaveRequest|OvertimeRequest>  $model
     */
    private function pendingQuery(string $model, User $user): mixed
    {
        return $model::query()->where('user_id', $user->getKey())->where('status', RequestStatus::Pending->value);
    }
}
