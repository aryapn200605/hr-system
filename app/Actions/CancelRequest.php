<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CancelRequest
{
    /**
     * Cancel a pending request of the actor. False means it is no longer pending, such as when
     * a second click came first. The one conditional update lets only one of them win, and the
     * row stays, with its dates free again because cancelled requests no longer hold them.
     *
     * @throws AuthorizationException
     */
    public function handle(LeaveRequest|OvertimeRequest $request, User $actor): bool
    {
        if ($request->user_id !== $actor->getKey()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($request, $actor) {
            $cancelled = $request->newQuery()
                ->whereKey($request->getKey())
                ->where('status', RequestStatus::Pending)
                ->update(['status' => RequestStatus::Cancelled, 'cancelled_at' => now()]) === 1;

            if ($cancelled) {
                AuditLog::record($request::CANCELLED_EVENT, $actor, $request);
            }

            return $cancelled;
        });
    }
}
