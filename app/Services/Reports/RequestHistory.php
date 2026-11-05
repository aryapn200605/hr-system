<?php

namespace App\Services\Reports;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Leave and overtime requests together, as one query of flat rows (HistoryRow), limited by Visibility
 * and the filters. The page paginates it and the export streams it, so both always show the same rows.
 */
class RequestHistory
{
    public function __construct(private Visibility $visibility) {}

    /**
     * Newest submitted first. Ties are broken by type and ID, so pages never overlap.
     */
    public function query(User $actor, ReportFilters $filters): Builder
    {
        $parts = [];

        if ($filters->type !== 'overtime') {
            $parts[] = $this->leave($actor, $filters);
        }

        // A category belongs to leave only, so it leaves no overtime row.
        if ($filters->type !== 'leave' && $filters->category === null) {
            $parts[] = $this->overtime($actor, $filters);
        }

        if ($parts === []) {
            return $this->leave($actor, $filters)->whereRaw('1 = 0');
        }

        $union = array_shift($parts);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return $union->orderByDesc('created_at')->orderBy('type')->orderByDesc('id');
    }

    private function leave(User $actor, ReportFilters $filters): Builder
    {
        $query = $this->base('leave_requests', $actor, $filters)
            ->join('leave_categories as c', 'c.id', '=', 'r.leave_category_id')
            ->select($this->columns("'leave'", 'c.name', 'r.starts_on', 'r.ends_on', 'r.working_days'))
            ->when($filters->category, fn (Builder $query, string $code) => $query->where('c.code', $code));

        return $this->overlapping($query, $filters, 'r.starts_on', 'r.ends_on');
    }

    private function overtime(User $actor, ReportFilters $filters): Builder
    {
        $query = $this->base('overtime_requests', $actor, $filters)
            ->select($this->columns("'overtime'", 'NULL', 'r.start_at', 'r.end_at', 'r.hours'));

        // The dates an overtime request touches: from its start to its last hour worked.
        return $this->overlapping($query, $filters, 'r.first_date', 'r.last_date');
    }

    private function base(string $table, User $actor, ReportFilters $filters): Builder
    {
        $query = DB::table($table.' as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->leftJoin('users as k', 'k.id', '=', 'r.decided_by')
            ->when($filters->employee, fn (Builder $query, int $id) => $query->where('r.user_id', $id))
            ->when($filters->status, fn (Builder $query, $status) => $query->where('r.status', $status->value));

        // The department is the requester's department now, like the supervisor relation.
        $filters->applyDepartment($query, 'u.department_id');

        $this->visibility->apply($query, $actor, 'r.user_id');

        return $query;
    }

    /**
     * Requests whose dates overlap the chosen range, either end open.
     */
    private function overlapping(Builder $query, ReportFilters $filters, string $first, string $last): Builder
    {
        return $query
            ->when($filters->from, fn (Builder $query, $from) => $query->where($last, '>=', $from->toDateString()))
            ->when($filters->to, fn (Builder $query, $to) => $query->where($first, '<=', $to->toDateString()));
    }

    /**
     * @return list<mixed>
     */
    private function columns(string $type, string $category, string $start, string $end, string $amount): array
    {
        return [
            DB::raw("$type as type"),
            'r.id',
            'r.user_id',
            'u.name as requester',
            'd.name as department',
            DB::raw("$category as category_name"),
            DB::raw("CAST($start AS DATETIME) as starts"),
            DB::raw("CAST($end AS DATETIME) as ends"),
            DB::raw("$amount as amount"),
            'r.status',
            'r.created_at',
            'r.decided_by',
            'k.name as decider',
            'r.system_reason',
            'r.decided_at',
        ];
    }
}
