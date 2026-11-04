<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Livewire\History\Index as HistoryPage;
use App\Livewire\Recap\Index as RecapPage;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Reports\BalanceLine;
use App\Services\Reports\BalanceRecap;
use App\Services\Reports\DepartmentRecap;
use App\Services\Reports\HistoryRow;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\RequestHistory;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports of history and recap: the rows the page shows for the same filters, read through the
 * same services, so an export never holds a row its user may not see. No reason, note or evidence
 * column. Each export writes an audit row with its filters and the number of rows, never the data.
 */
class ExportController extends Controller
{
    private const DATE = 'Y-m-d';

    private const DATETIME = 'Y-m-d H:i';

    public function history(Request $request, RequestHistory $history): StreamedResponse
    {
        $user = $request->user();
        $filters = $this->filters($request, HistoryPage::FILTERS);

        return Csv::download($this->filename('request-history'), [
            __('Type'), __('Request ID'), __('Employee'), __('Department'), __('Category'),
            __('Start'), __('End'), __('Working days'), __('Hours'), __('Status'),
            __('Submitted (WIB)'), __('Decided by'), __('Decided (WIB)'),
        ], function () use ($history, $user, $filters) {
            foreach ($history->query($user, $filters)->cursor() as $record) {
                $row = HistoryRow::fromRecord($record);
                $format = $row->isLeave() ? self::DATE : self::DATETIME;

                yield [
                    $row->isLeave() ? __('Leave') : __('Overtime'),
                    $row->id,
                    $row->requester,
                    $row->department ?? '',
                    $row->isLeave() ? $row->kindLabel() : '',
                    $row->start->format($format),
                    $row->end->format($format),
                    $row->isLeave() ? $row->amount : '',
                    $row->isLeave() ? '' : $row->amount,
                    $row->status->label(),
                    $row->submittedAt->format(self::DATETIME),
                    $row->decider ?? '',
                    $row->decidedAt?->format(self::DATETIME) ?? '',
                ];
            }
        }, fn (int $count) => $this->audit($user, 'history', $filters->only(HistoryPage::FILTERS), $count));
    }

    public function balance(Request $request, BalanceRecap $recap): StreamedResponse
    {
        $user = $request->user();
        $filters = $this->filters($request, RecapPage::BALANCE_FILTERS);

        return Csv::download($this->filename('leave-balance'), [
            __('Employee'), __('Department'), __('Period start'), __('Period end'), __('Projected'),
            __('Entitlement'), __('Used'), __('Approved, not deducted yet'), __('In pending requests'), __('Remaining'),
        ], function () use ($recap, $user, $filters) {
            // A few hundred users at a time, each batch with the same three queries.
            $query = $recap->users($user, $filters);

            for ($page = 1; ($users = $query->clone()->forPage($page, 200)->get())->isNotEmpty(); $page++) {
                $lines = $recap->lines($users, $filters->allPeriods);

                foreach ($users as $member) {
                    foreach ($lines[$member->getKey()] ?? [] as $line) {
                        /** @var BalanceLine $line */
                        yield [
                            $member->name,
                            $member->department?->name ?? '',
                            $line->startsOn->format(self::DATE),
                            $line->endsOn->format(self::DATE),
                            $line->projected ? __('Yes') : __('No'),
                            $line->entitlement,
                            $line->used,
                            $line->approvedNotDeducted,
                            $line->pending,
                            $line->remaining(),
                        ];
                    }
                }
            }
        }, fn (int $count) => $this->audit($user, 'leave_balance', $filters->only(RecapPage::BALANCE_FILTERS), $count));
    }

    public function departments(Request $request, DepartmentRecap $recap): StreamedResponse
    {
        $user = $request->user();
        $filters = $this->filters($request, RecapPage::DEPARTMENT_FILTERS);
        $categories = $recap->categories();

        return Csv::download($this->filename('requests-by-department'), [
            __('Department'), __('Month'),
            ...$categories->map->label()->all(),
            __('Overtime requests'), __('Overtime hours'),
        ], function () use ($recap, $user, $filters, $categories) {
            foreach ($recap->rows($user, $filters) as $row) {
                yield [
                    $row['department'] ?? __('No department'),
                    sprintf('%04d-%02d', $filters->year, $row['month']),
                    ...$categories->map(fn ($category) => $row['leave'][$category->id] ?? 0)->all(),
                    $row['overtime_requests'],
                    $row['overtime_hours'],
                ];
            }
        }, fn (int $count) => $this->audit($user, 'requests_by_department', $filters->only(RecapPage::DEPARTMENT_FILTERS), $count));
    }

    /**
     * Only the filters the page offers, so the export and its audit row match the page.
     *
     * @param  list<string>  $keys
     */
    private function filters(Request $request, array $keys): ReportFilters
    {
        return ReportFilters::from(Arr::only($request->query(), $keys));
    }

    private function filename(string $name): string
    {
        return $name.'-'.CarbonImmutable::now()->format('Ymd-Hi').'.csv';
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function audit(User $user, string $report, array $filters, int $rows): void
    {
        AuditLog::record(AuditEvent::ReportExported, $user, metadata: [
            'report' => $report,
            'filters' => $filters,
            'rows' => $rows,
        ]);
    }
}
