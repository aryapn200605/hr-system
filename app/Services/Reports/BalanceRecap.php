<?php

namespace App\Services\Reports;

use App\Enums\RequestStatus;
use App\Models\LeavePeriod;
use App\Models\User;
use App\Services\LeaveBalance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave balance per user and period, from the stored periods and allocations only: it reads and never
 * opens a period. For any number of users it runs the same three queries (users, periods, allocations).
 *
 * Days are counted by the first day of each allocation part, as My requests and approvals count them.
 */
class BalanceRecap
{
    public function __construct(private Visibility $visibility, private LeaveBalance $balance) {}

    /**
     * The users this actor may see, filtered, by name.
     *
     * @return Builder<User>
     */
    public function users(User $actor, ReportFilters $filters): Builder
    {
        $query = User::query()->with('department:id,name')
            ->when($filters->employee, fn (Builder $query, int $id) => $query->whereKey($id));

        $filters->applyDepartment($query, 'users.department_id');

        $this->visibility->apply($query, $actor, 'users.id');

        return $query->orderBy('name')->orderBy('id');
    }

    /**
     * The periods of these users, oldest first, by user ID: every real period or only the latest, plus
     * the projected next one.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, list<BalanceLine>>
     */
    public function lines(Collection $users, bool $allPeriods): array
    {
        $ids = $users->modelKeys();

        if ($ids === []) {
            return [];
        }

        $periods = LeavePeriod::query()->whereIn('user_id', $ids)->orderBy('user_id')->orderBy('starts_on')->get()->groupBy('user_id');

        $lines = [];

        foreach ($users as $user) {
            $own = $periods->get($user->getKey(), collect());

            if ($own->isEmpty()) {
                $lines[$user->getKey()] = [];

                continue;
            }

            $shown = $allPeriods ? $own : $own->slice(-1);
            $list = $shown->map(fn (LeavePeriod $period) => new BalanceLine(
                CarbonImmutable::instance($period->starts_on),
                CarbonImmutable::instance($period->ends_on),
                false,
                $period->entitlement,
                $period->used,
            ))->values()->all();

            $start = CarbonImmutable::instance($own->last()->ends_on)->addDay();
            $list[] = new BalanceLine($start, $this->balance->endFor(CarbonImmutable::instance($user->leave_anchor_date), $start), true, LeaveBalance::ENTITLEMENT, 0);

            $lines[$user->getKey()] = $list;
        }

        // Pending parts, and approved parts not charged yet (waiting for their period to open).
        $parts = DB::table('leave_request_allocations as a')
            ->join('leave_requests as r', 'r.id', '=', 'a.leave_request_id')
            ->whereIn('r.user_id', $ids)
            ->where(fn ($query) => $query->where('r.status', RequestStatus::Pending->value)
                ->orWhere(fn ($query) => $query->where('r.status', RequestStatus::Approved->value)->whereNull('a.charged_at')))
            ->get(['r.user_id', 'r.status', 'a.starts_on', 'a.days']);

        foreach ($parts as $part) {
            $date = CarbonImmutable::parse($part->starts_on);

            foreach ($lines[$part->user_id] ?? [] as $line) {
                if ($line->contains($date)) {
                    $part->status === RequestStatus::Pending->value
                        ? $line->pending += (int) $part->days
                        : $line->approvedNotDeducted += (int) $part->days;

                    break;
                }
            }
        }

        return $lines;
    }
}
