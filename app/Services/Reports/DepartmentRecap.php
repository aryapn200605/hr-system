<?php

namespace App\Services\Reports;

use App\Models\LeaveCategory;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Requests per department and month of one year: leave requests per category, overtime requests and
 * their hours. Two grouped queries whatever the amount of data.
 *
 * - The department is the requester's department now, like the supervisor relation.
 * - A request counts in the month it starts: a leave by its start date, an overtime by its start time.
 * - Only Approved requests count unless another status, or every status, is chosen.
 */
class DepartmentRecap
{
    public function __construct(private Visibility $visibility) {}

    /**
     * @return Collection<int, LeaveCategory>
     */
    public function categories(): Collection
    {
        return LeaveCategory::query()->orderBy('id')->get();
    }

    /**
     * One row per department and month that has at least one request, by department name (no
     * department last), then month.
     *
     * @return list<array{department_id: ?int, department: ?string, month: int, leave: array<int, int>, overtime_requests: int, overtime_hours: int}>
     */
    public function rows(User $actor, ReportFilters $filters): array
    {
        $from = $filters->year.'-01-01';
        $to = $filters->year.'-12-31';

        $leave = $this->base('leave_requests', $actor, $filters)
            ->whereBetween('r.starts_on', [$from, $to])
            ->groupBy('u.department_id', 'd.name', DB::raw('MONTH(r.starts_on)'), 'r.leave_category_id')
            ->get(['u.department_id', 'd.name as department', DB::raw('MONTH(r.starts_on) as month'), 'r.leave_category_id', DB::raw('COUNT(*) as requests')]);

        $overtime = $this->base('overtime_requests', $actor, $filters)
            ->where('r.start_at', '>=', $from.' 00:00:00')
            ->where('r.start_at', '<', ($filters->year + 1).'-01-01 00:00:00')
            ->groupBy('u.department_id', 'd.name', DB::raw('MONTH(r.start_at)'))
            ->get(['u.department_id', 'd.name as department', DB::raw('MONTH(r.start_at) as month'), DB::raw('COUNT(*) as requests'), DB::raw('SUM(r.hours) as hours')]);

        $rows = [];
        $row = function (object $record) use (&$rows): string {
            $key = ($record->department_id ?? 'none').'-'.$record->month;

            $rows[$key] ??= [
                'department_id' => $record->department_id === null ? null : (int) $record->department_id,
                'department' => $record->department,
                'month' => (int) $record->month,
                'leave' => [],
                'overtime_requests' => 0,
                'overtime_hours' => 0,
            ];

            return $key;
        };

        foreach ($leave as $record) {
            $rows[$row($record)]['leave'][(int) $record->leave_category_id] = (int) $record->requests;
        }

        foreach ($overtime as $record) {
            $key = $row($record);
            $rows[$key]['overtime_requests'] = (int) $record->requests;
            $rows[$key]['overtime_hours'] = (int) $record->hours;
        }

        $rows = array_values($rows);

        usort($rows, fn (array $a, array $b) => [$a['department'] === null, mb_strtolower((string) $a['department']), $a['department_id'], $a['month']]
            <=> [$b['department'] === null, mb_strtolower((string) $b['department']), $b['department_id'], $b['month']]);

        return $rows;
    }

    /**
     * The years the year filter offers: from the first request's year (or this year) to next year.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $first = min(array_filter([
            DB::table('leave_requests')->min('starts_on'),
            DB::table('overtime_requests')->min('start_at'),
        ]) ?: [today()->toDateString()]);

        return range((int) substr($first, 0, 4), today()->year + 1);
    }

    private function base(string $table, User $actor, ReportFilters $filters): Builder
    {
        $query = DB::table($table.' as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->when($filters->recapStatus(), fn (Builder $query, $status) => $query->where('r.status', $status->value))
            ->when($filters->employee, fn (Builder $query, int $id) => $query->where('r.user_id', $id));

        $filters->applyDepartment($query, 'u.department_id');

        $this->visibility->apply($query, $actor, 'r.user_id');

        return $query;
    }
}
