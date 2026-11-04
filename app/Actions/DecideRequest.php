<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Exceptions\InsufficientLeaveBalance;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\LeaveBalances;
use App\Support\LeaveDeduction;
use App\Support\RequestDecisions;
use App\Support\RequestLocks;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The real decision. Every check runs again here, under locks taken in one order everywhere:
 * the HR Admin role row, the requester, the request, then leave periods by date.
 *
 * The role row is what suspensions, role changes and supervisor changes hold, so a decider cannot
 * lose their right halfway through. The requester's row is what balance changes hold first.
 */
class DecideRequest
{
    /**
     * Approve a request. Null means it went through, and a string says why it did not.
     *
     * @throws AuthorizationException
     */
    public function approve(LeaveRequest|OvertimeRequest $request, User $actor, ?string $note = null): ?string
    {
        return $this->decide($request, $actor, RequestStatus::Approved, ['decision_note' => $note ?: null]);
    }

    /**
     * Reject a request, which never touches a balance. Null means it went through.
     *
     * @throws AuthorizationException
     */
    public function reject(LeaveRequest|OvertimeRequest $request, User $actor, string $reason): ?string
    {
        return $this->decide($request, $actor, RequestStatus::Rejected, ['rejection_reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $details
     *
     * @throws AuthorizationException
     */
    private function decide(LeaveRequest|OvertimeRequest $request, User $actor, RequestStatus $status, array $details): ?string
    {
        $forbidden = false;

        try {
            $message = User::withinHrAdminLock(function () use ($request, $actor, $status, $details, &$forbidden) {
                $requester = LeaveBalances::lockUser(User::query()->findOrFail($request->user_id));
                $locked = $request->newQuery()->lockForUpdate()->findOrFail($request->getKey());

                if (! $locked->isPending()) {
                    return RequestDecisions::outcome($locked);
                }

                if (! RequestDecisions::canDecide($actor, $locked)) {
                    $forbidden = true;

                    return null;
                }

                if ($requester->status !== UserStatus::Active) {
                    return 'The requester is not active, so this request stays pending.';
                }

                $holder = RequestLocks::holder($locked);

                if ($holder && $holder->user_id !== $actor->getKey()) {
                    return $holder->user->name.' has this request open. Wait until they finish.';
                }

                // A category that does not deduct has no allocations, so there is nothing to apply.
                if ($locked instanceof LeaveRequest && $status === RequestStatus::Approved) {
                    LeaveBalances::rollOverLocked($requester, 'decision', null);
                    LeaveDeduction::apply($locked, $requester, $actor);
                }

                $locked->forceFill(['status' => $status, 'decided_by' => $actor->getKey(), 'decided_at' => now(), ...$details])->save();

                RequestLocks::clear($locked);

                AuditLog::record(
                    $status === RequestStatus::Approved ? AuditEvent::RequestApproved : AuditEvent::RequestRejected,
                    $actor,
                    $locked,
                    ['requester_id' => $locked->user_id],
                );

                return null;
            });
        } catch (InsufficientLeaveBalance $exception) {
            // After the rollback, which would otherwise take this entry with it.
            AuditLog::record(AuditEvent::RequestApprovalRefused, $actor, $request, [
                'period' => $exception->period,
                'needed' => $exception->needed,
                'left' => $exception->left,
            ]);

            return $exception->getMessage();
        }

        if ($forbidden) {
            AuditLog::record(AuditEvent::RequestDecisionForbidden, $actor, $request, ['requester_id' => $request->user_id]);

            throw new AuthorizationException;
        }

        return $message;
    }
}
