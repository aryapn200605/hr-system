<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsReportData;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase, SeedsReportData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReportData();
    }

    private function csv(TestResponse $response): string
    {
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        return $response->streamedContent();
    }

    /**
     * The lines of a CSV body without the BOM, each split on the separator.
     *
     * @return list<list<string>>
     */
    private function lines(string $body, string $delimiter = ';'): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = explode("\r\n", rtrim(substr($body, 3), "\r\n"));

        return array_map(fn (string $line) => str_getcsv($line, $delimiter, '"', ''), $lines);
    }

    public function test_the_history_export_is_a_semicolon_csv_with_bom_crlf_and_wib_times(): void
    {
        $response = $this->actingAs($this->eko)->get(route('history.export'));
        $body = $this->csv($response);

        $this->assertStringContainsString('attachment; filename=request-history-20261104-1200.csv', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $body));

        $this->assertSame([
            ['Type', 'Request ID', 'Employee', 'Department', 'Category', 'Start', 'End', 'Working days', 'Hours', 'Status', 'Submitted (WIB)', 'Decided by', 'Decided (WIB)'],
            ['Overtime', (string) $this->requests['eko-overtime']->id, 'Eko Employee', 'Finance', '', '2026-11-05 17:00', '2026-11-05 20:00', '', '3', 'Pending approval', '2026-11-04 10:01', '', ''],
            ['Leave', (string) $this->requests['eko-leave']->id, 'Eko Employee', 'Finance', 'Annual leave', '2026-11-09', '2026-11-10', '2', '', 'Approved', '2026-11-04 10:00', 'Siti Supervisor', '2026-11-04 10:00'],
        ], $this->lines($body));
    }

    public function test_the_export_holds_only_rows_the_user_may_see_with_the_active_filters(): void
    {
        $this->assertCount(3, $this->lines($this->csv($this->actingAs($this->eko)->get(route('history.export')))));
        // A forged employee filter narrows to nothing.
        $this->assertCount(1, $this->lines($this->csv($this->actingAs($this->eko)->get(route('history.export', ['employee' => $this->rina->id])))));
        $this->assertCount(4, $this->lines($this->csv($this->actingAs($this->siti)->get(route('history.export')))));
        $this->assertCount(6, $this->lines($this->csv($this->actingAs($this->hana)->get(route('history.export')))));

        $lines = $this->lines($this->csv($this->actingAs($this->hana)->get(route('history.export', ['type' => 'overtime', 'status' => 'approved']))));
        $this->assertCount(2, $lines);
        $this->assertSame('Siti Supervisor', $lines[1][2]);

        // A category without a type still leaves out overtime, as on the page.
        $this->assertSame(['Sick leave'], array_column(array_slice($this->lines($this->csv($this->actingAs($this->hana)->get(route('history.export', ['category' => 'sick'])))), 1), 4));

        // Array values in the query string count as no filter.
        $this->assertCount(6, $this->lines($this->csv($this->actingAs($this->hana)->get('/history/export?employee[]=1&status[]=pending'))));
    }

    public function test_no_reason_note_or_evidence_is_exported(): void
    {
        foreach (['history.export', 'recap.balance.export', 'recap.departments.export'] as $route) {
            $body = $this->csv($this->actingAs($this->hana)->get(route($route, ['status' => 'all'])));

            $this->assertStringNotContainsString(self::REASON, $body, $route);
            $this->assertStringNotContainsString('Not this week, sorry.', $body, $route);
            $this->assertStringNotContainsString('evidence', strtolower($body), $route);
            $this->assertStringNotContainsString($this->eko->email, $body, $route);
            $this->assertStringNotContainsString($this->eko->username, $body, $route);
        }
    }

    public function test_cells_that_could_be_formulas_get_a_prefix(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $value) {
            $this->assertSame("'".$value, Csv::cell($value));
        }

        foreach (['Eko', '1', '', ' =1', 'x=1'] as $value) {
            $this->assertSame($value, Csv::cell($value));
        }

        $this->eko->forceFill(['name' => '=HYPERLINK("http://x","y")'])->save();
        $this->finance->forceFill(['name' => '@Finance'])->save();

        $lines = $this->lines($this->csv($this->actingAs($this->eko)->get(route('history.export'))));
        $this->assertSame("'=HYPERLINK(\"http://x\",\"y\")", $lines[1][2]);
        $this->assertSame("'@Finance", $lines[1][3]);

        $balance = $this->lines($this->csv($this->actingAs($this->eko)->get(route('recap.balance.export'))));
        $this->assertSame("'=HYPERLINK(\"http://x\",\"y\")", $balance[1][0]);

        $departments = $this->lines($this->csv($this->actingAs($this->eko)->get(route('recap.departments.export'))));
        $this->assertSame("'@Finance", $departments[1][0]);
    }

    public function test_the_separator_follows_the_configuration(): void
    {
        config(['requests.csv_delimiter' => ',']);
        $lines = $this->lines($this->csv($this->actingAs($this->eko)->get(route('history.export'))), ',');
        $this->assertSame('Request ID', $lines[0][1]);

        foreach (['"', '', ';;', "\n", null] as $invalid) {
            config(['requests.csv_delimiter' => $invalid]);
            $this->assertSame(';', Csv::delimiter());
        }
    }

    public function test_the_balance_export(): void
    {
        $this->leaveRequest($this->eko, 'annual', '2026-11-11', '2026-11-13');

        $this->assertSame([
            ['Employee', 'Department', 'Period start', 'Period end', 'Projected', 'Entitlement', 'Used', 'Approved, not deducted yet', 'In pending requests', 'Remaining'],
            ['Eko Employee', 'Finance', '2026-11-04', '2027-11-03', 'No', '12', '2', '0', '3', '10'],
            ['Eko Employee', 'Finance', '2027-11-04', '2028-11-03', 'Yes', '12', '0', '0', '0', '12'],
        ], $this->lines($this->csv($this->actingAs($this->eko)->get(route('recap.balance.export')))));

        // Siti sees herself and Eko; HR Admin sees all five, two lines each.
        $this->assertCount(5, $this->lines($this->csv($this->actingAs($this->siti)->get(route('recap.balance.export')))));
        $this->assertCount(11, $this->lines($this->csv($this->actingAs($this->hana)->get(route('recap.balance.export')))));
        $this->assertCount(3, $this->lines($this->csv($this->actingAs($this->hana)->get(route('recap.balance.export', ['department' => $this->sales->id])))));
    }

    public function test_the_balance_export_reads_users_in_batches_with_the_same_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->csv($this->actingAs($this->hana)->get(route('recap.balance.export', ['periods' => 'all'])));
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $count();
        $before = $count();

        foreach (range(1, 8) as $i) {
            $this->supervisedMember(attributes: ['department_id' => $this->finance->id]);
        }

        $this->assertSame($before, $count());
    }

    public function test_the_department_export(): void
    {
        $this->assertSame([
            ['Department', 'Month', 'Annual leave', 'Sick leave', 'Other leave', 'Overtime requests', 'Overtime hours'],
            ['Finance', '2026-11', '1', '0', '0', '1', '3'],
            ['Sales', '2026-11', '0', '1', '0', '0', '0'],
            ['No department', '2026-11', '0', '0', '1', '1', '4'],
        ], $this->lines($this->csv($this->actingAs($this->hana)->get(route('recap.departments.export', ['status' => 'all'])))));

        $this->assertSame([
            ['Department', 'Month', 'Annual leave', 'Sick leave', 'Other leave', 'Overtime requests', 'Overtime hours'],
            ['Finance', '2026-11', '1', '0', '0', '0', '0'],
        ], $this->lines($this->csv($this->actingAs($this->eko)->get(route('recap.departments.export')))));

        // The page has no employee filter on this tab, so the export ignores one too.
        $this->assertCount(4, $this->lines($this->csv($this->actingAs($this->hana)->get(route('recap.departments.export', ['status' => 'all', 'employee' => $this->eko->id])))));
    }

    public function test_every_export_writes_an_audit_row_with_filters_and_count_only(): void
    {
        $this->csv($this->actingAs($this->hana)->get(route('history.export', ['type' => 'leave', 'from' => '2026-11-01', 'employee' => $this->eko->id, 'periods' => 'all'])));
        $this->csv($this->actingAs($this->siti)->get(route('recap.balance.export', ['periods' => 'all'])));
        $this->csv($this->actingAs($this->eko)->get(route('recap.departments.export', ['year' => '2026', 'status' => 'all'])));

        $rows = AuditLog::query()->where('event', AuditEvent::ReportExported->value)->orderBy('id')->get();

        $this->assertCount(3, $rows);
        $this->assertSame([$this->hana->id, $this->siti->id, $this->eko->id], $rows->pluck('actor_id')->all());
        $this->assertEquals(['report' => 'history', 'filters' => ['employee' => (string) $this->eko->id, 'type' => 'leave', 'from' => '2026-11-01'], 'rows' => 1], $rows[0]->metadata);
        $this->assertEquals(['report' => 'leave_balance', 'filters' => ['periods' => 'all'], 'rows' => 4], $rows[1]->metadata);
        $this->assertEquals(['report' => 'requests_by_department', 'filters' => ['year' => '2026', 'status' => 'all'], 'rows' => 1], $rows[2]->metadata);

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Eko Employee', json_encode($row->metadata));
        }
    }

    public function test_exports_need_the_permission_and_a_login(): void
    {
        $nobody = User::factory()->twoFactorEnrolled()->create();

        foreach (['history.export', 'recap.balance.export', 'recap.departments.export'] as $route) {
            $this->actingAs($nobody)->get(route($route))->assertForbidden();
        }

        auth()->logout();

        foreach (['history.export', 'recap.balance.export', 'recap.departments.export'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }

        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::ReportExported->value)->count());
    }

    public function test_exports_are_limited_per_user(): void
    {
        foreach (range(1, 10) as $i) {
            $this->actingAs($this->eko)->get(route('recap.departments.export'))->assertOk();
        }

        $this->actingAs($this->eko)->get(route('history.export'))->assertTooManyRequests();
        // Another user has their own limit.
        $this->actingAs($this->rina)->get(route('history.export'))->assertOk();
    }

    public function test_headers_and_labels_follow_the_users_language(): void
    {
        $this->eko->forceFill(['locale' => 'id'])->save();

        $lines = $this->lines($this->csv($this->actingAs($this->eko)->get(route('history.export'))));

        $this->assertSame('Karyawan', $lines[0][2]);
        $this->assertSame('Cuti tahunan', $lines[2][4]);
        $this->assertSame('Disetujui', $lines[2][9]);
    }

    public function test_the_page_export_links_carry_the_active_filters(): void
    {
        $this->actingAs($this->hana)->get(route('history.index', ['status' => 'approved', 'type' => 'leave']))
            ->assertSee(e(route('history.export', ['type' => 'leave', 'status' => 'approved'])), false);

        $this->actingAs($this->hana)->get(route('recap.index', ['tab' => 'departments', 'status' => 'pending']))
            ->assertSee(e(route('recap.departments.export', ['year' => '2026', 'status' => 'pending'])), false);
    }
}
