<?php

namespace App\Services\Reports;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Models\User;
use App\Services\Requests\Deciders;
use App\Services\Requests\LeavePeriodSlot;
use App\Services\Requests\LeaveRequests;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The request and leave numbers of the dashboard, each computed only for a user entitled to it, in a
 * fixed number of queries whatever the size of the organization. No role name is read:
 *
 * - requests.submit: their own balance (the same numbers as My requests), pending requests and last decision;
 * - requests.decide: requests waiting for their decision (App\Services\Requests\Deciders, as the inbox);
 * - requests.view-all: pending requests in the whole organization;
 * - users.view: Employees and HR Admins without an active supervisor (the user list's "No supervisor" filter).
 *
 * A number the user is not entitled to is null, never zero, so the page cannot show it by mistake.
 */
class DashboardSummary
{
    public function __construct(private Deciders $deciders, private LeaveRequests $leaveRequests) {}

    /**
     * @return array{
     *     balance: ?LeavePeriodSlot,
     *     ownPending: ?int,
     *     lastDecided: ?object,
     *     waitingForMe: ?int,
     *     pendingTotal: ?int,
     *     withoutSupervisor: ?int,
     * }
     */
    public function for(User $user): array
    {
        $submits = $user->hasPermissionTo(PermissionName::RequestsSubmit);
        $decides = $user->hasPermissionTo(PermissionName::RequestsDecide);
        $seesAll = $user->hasPermissionTo(PermissionName::RequestsViewAll);

        $counts = $this->counts($user, $submits, $decides, $seesAll);

        return [
            // The current period: the first slot, the one My requests shows first.
            'balance' => $submits ? $this->leaveRequests->slots($user)[0] : null,
            'ownPending' => $counts['own'] ?? null,
            'lastDecided' => $submits ? $this->lastDecided($user) : null,
            'waitingForMe' => $counts['waiting'] ?? null,
            'pendingTotal' => $counts['total'] ?? null,
            'withoutSupervisor' => $user->hasPermissionTo(PermissionName::UsersView) ? User::query()->lackingSupervisor()->count() : null,
        ];
    }

    /**
     * Every pending count in one query over the pending rows of both request tables.
     *
     * @return array<string, int>
     */
    private function counts(User $user, bool $submits, bool $decides, bool $seesAll): array
    {
        if (! $submits && ! $decides && ! $seesAll) {
            return [];
        }

        $pending = fn (string $table): Builder => DB::table($table)->select('user_id')->where('status', RequestStatus::Pending->value);

        $query = DB::query()->fromSub($pending('leave_requests')->unionAll($pending('overtime_requests')), 'p');

        if ($submits) {
            $query->selectRaw('coalesce(sum(p.user_id = ?), 0) as own', [$user->getKey()]);
        }

        if ($decides) {
            $requesters = $this->deciders->requesterIds($user)->toBase();
            $query->selectRaw('coalesce(sum(p.user_id in ('.$requesters->toSql().')), 0) as waiting', $requesters->getBindings());
        }

        if ($seesAll) {
            $query->selectRaw('count(*) as total');
        }

        return array_map('intval', (array) $query->first());
    }

    /**
     * The user's own request decided most recently (approved or rejected), as a flat row: type, id,
     * status, category_name (leave only), starts, ends, decided_at. Null when none has been decided.
     */
    private function lastDecided(User $user): ?object
    {
        $decided = [RequestStatus::Approved->value, RequestStatus::Rejected->value];

        $leave = DB::table('leave_requests as r')
            ->join('leave_categories as c', 'c.id', '=', 'r.leave_category_id')
            ->selectRaw("'leave' as type, r.id, r.status, c.name as category_name, r.starts_on as starts, r.ends_on as ends, r.decided_at")
            ->where('r.user_id', $user->getKey())
            ->whereIn('r.status', $decided);

        $overtime = DB::table('overtime_requests as r')
            ->selectRaw("'overtime' as type, r.id, r.status, null as category_name, r.start_at as starts, r.end_at as ends, r.decided_at")
            ->where('r.user_id', $user->getKey())
            ->whereIn('r.status', $decided);

        $row = $leave->unionAll($overtime)->orderByDesc('decided_at')->orderByDesc('type')->orderByDesc('id')->first();

        if ($row === null) {
            return null;
        }

        return (object) [
            'type' => $row->type,
            'id' => (int) $row->id,
            'status' => RequestStatus::from($row->status),
            'categoryName' => $row->category_name,
            'starts' => CarbonImmutable::parse($row->starts),
            'ends' => CarbonImmutable::parse($row->ends),
            'decidedAt' => CarbonImmutable::parse($row->decided_at),
        ];
    }
}
