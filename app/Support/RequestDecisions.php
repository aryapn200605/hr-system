<?php

namespace App\Support;

use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one place that says who may decide a request. Everything reads current roles, relations
 * and status from the database, because they can change after a request is made.
 *
 * An Employee or HR Admin is decided by an active Approver linked to them. An Approver is decided
 * by any active HR Admin, without a relation. Nobody decides their own request.
 */
class RequestDecisions
{
    /**
     * Determine whether this person may decide this request right now.
     */
    public static function canDecide(User $actor, LeaveRequest|OvertimeRequest $request): bool
    {
        if ($actor->getKey() === $request->user_id) {
            return false;
        }

        $requester = User::query()->with('roles')->find($request->user_id);
        $role = RoleName::tryFrom((string) $requester?->roleName());

        if (! $role) {
            return false;
        }

        return $role->needsSupervisor()
            ? $requester->supervisors()->activeSupervisor()->whereKey($actor->getKey())->exists()
            : User::query()->activeHrAdmin()->whereKey($actor->getKey())->exists();
    }

    /**
     * Get the pending requests of one kind that this person may decide, oldest first.
     *
     * @param  class-string<LeaveRequest|OvertimeRequest>  $model
     * @return Builder<LeaveRequest|OvertimeRequest>
     */
    public static function queue(User $actor, string $model): Builder
    {
        return $model::query()
            ->where('status', RequestStatus::Pending)
            ->whereIn('user_id', static::requesterIds($actor))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Count what is waiting for this person, for the menu.
     */
    public static function pendingCount(User $actor): int
    {
        return static::queue($actor, LeaveRequest::class)->count() + static::queue($actor, OvertimeRequest::class)->count();
    }

    /**
     * Get the people whose requests this person decides, without themselves.
     *
     * @return Collection<int, int>
     */
    public static function requesterIds(User $actor): Collection
    {
        $withSupervisor = array_filter(RoleName::cases(), fn (RoleName $role) => $role->needsSupervisor());
        $withoutSupervisor = array_diff_key(RoleName::cases(), $withSupervisor);

        $ids = collect();

        if (User::query()->whereKey($actor->getKey())->activeSupervisor()->exists()) {
            $ids = $ids->merge($actor->subordinates()->withRoles($withSupervisor)->pluck('users.id'));
        }

        if (User::query()->activeHrAdmin()->whereKey($actor->getKey())->exists()) {
            $ids = $ids->merge(User::query()->withRoles($withoutSupervisor)->pluck('id'));
        }

        return $ids->reject(fn (int $id) => $id === $actor->getKey())->unique()->values();
    }

    /**
     * Say what became of a request that can no longer be decided or cancelled.
     */
    public static function outcome(LeaveRequest|OvertimeRequest $request): string
    {
        if ($request->status === RequestStatus::Cancelled) {
            return 'The requester already cancelled this request at '.$request->cancelled_at->format('j M Y, H:i').' WIB.';
        }

        if ($request->decided_at) {
            return sprintf(
                'Already %s by %s at %s WIB.',
                $request->status->value,
                $request->decider?->name ?? 'someone else',
                $request->decided_at->format('j M Y, H:i'),
            );
        }

        return 'This request is no longer pending.';
    }
}
