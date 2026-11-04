<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Livewire\Recap\Index;
use App\Models\LeavePeriod;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Services\Requests\Decisions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\SeedsReportData;
use Tests\TestCase;

class RecapTest extends TestCase
{
    use RefreshDatabase, SeedsReportData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReportData();
    }

    /**
     * The balance lines the page shows to this user, as [start, end, projected, entitlement, used, approved-not-deducted, pending, remaining], by user name.
     *
     * @param  array<string, string>  $filters
     * @return array<string, list<list<mixed>>>
     */
    private function balance(User $user, array $filters = []): array
    {
        $component = Livewire::actingAs($user)->test(Index::class);

        foreach ($filters as $name => $value) {
            $component->set($name, $value);
        }

        $lines = $component->viewData('lines');
        $result = [];

        foreach ($component->viewData('users') as $member) {
            $result[$member->name] = array_map(fn ($line) => [
                $line->startsOn->toDateString(), $line->endsOn->toDateString(), $line->projected,
                $line->entitlement, $line->used, $line->approvedNotDeducted, $line->pending, $line->remaining(),
            ], $lines[$member->id]);
        }

        return $result;
    }

    /**
     * The department rows, keyed "department-month", as [category code => count, ..., 'overtime' => n, 'hours' => n].
     *
     * @param  array<string, string>  $filters
     * @return array<string, array<string, int>>
     */
    private function departments(User $user, array $filters = []): array
    {
        $component = Livewire::actingAs($user)->test(Index::class)->set('tab', 'departments');

        foreach ($filters as $name => $value) {
            $component->set($name, $value);
        }

        $categories = $component->viewData('categories');
        $result = [];

        foreach ($component->viewData('rows') as $row) {
            $counts = [];

            foreach ($categories as $category) {
                $counts[$category->code] = $row['leave'][$category->id] ?? 0;
            }

            $result[($row['department'] ?? 'none').'-'.$row['month']] = $counts + ['overtime' => $row['overtime_requests'], 'hours' => $row['overtime_hours']];
        }

        return $result;
    }

    public function test_the_balance_shows_used_approved_not_deducted_and_pending_days_apart(): void
    {
        // Eko: 2 days used (approved 9–10 Nov), 3 days pending this period; 2 days approved and 1 pending in the next one.
        $this->leaveRequest($this->eko, 'annual', '2026-11-11', '2026-11-13');
        $next = $this->leaveRequest($this->eko, 'annual', '2027-11-08', '2027-11-09');
        app(Decisions::class)->approve($this->siti, 'leave', $next->id);
        $this->leaveRequest($this->eko, 'annual', '2027-11-15', '2027-11-15');
        // A non-deducting category leaves the balance alone.
        $this->leaveRequest($this->eko, 'sick', '2026-11-18', '2026-11-18');

        $this->assertSame([
            ['2026-11-04', '2027-11-03', false, 12, 2, 0, 3, 10],
            ['2027-11-04', '2028-11-03', true, 12, 0, 2, 1, 10],
        ], $this->balance($this->eko)['Eko Employee']);
    }

    public function test_the_balance_matches_my_requests_after_the_period_opens(): void
    {
        $next = $this->leaveRequest($this->eko, 'annual', '2027-11-08', '2027-11-09');
        app(Decisions::class)->approve($this->siti, 'leave', $next->id);

        $this->travelTo(now()->setDate(2027, 11, 5));
        app(LeaveBalance::class)->sync($this->eko);

        // Charged when the period opened: used now, nothing waiting.
        $this->assertSame(
            ['2027-11-04', '2028-11-03', false, 12, 2, 0, 0, 10],
            $this->balance($this->eko)['Eko Employee'][0],
        );
    }

    public function test_all_periods_include_closed_ones(): void
    {
        LeavePeriod::query()->create(['user_id' => $this->eko->id, 'starts_on' => '2025-11-04', 'ends_on' => '2026-11-03', 'entitlement' => 12, 'used' => 7]);

        $this->assertCount(2, $this->balance($this->eko)['Eko Employee']);
        $this->assertSame(
            [['2025-11-04', '2026-11-03', false, 12, 7, 0, 0, 5], ['2026-11-04', '2027-11-03', false, 12, 2, 0, 0, 10], ['2027-11-04', '2028-11-03', true, 12, 0, 0, 0, 12]],
            $this->balance($this->eko, ['periods' => 'all'])['Eko Employee'],
        );
    }

    public function test_the_balance_shows_only_the_users_one_may_see(): void
    {
        $this->assertSame(['Eko Employee'], array_keys($this->balance($this->eko)));
        $this->assertSame(['Eko Employee', 'Siti Supervisor'], array_keys($this->balance($this->siti)));
        $this->assertSame(['Budi Supervisor', 'Eko Employee', 'Hana HR', 'Rina Employee', 'Siti Supervisor'], array_keys($this->balance($this->hana)));

        // Forged filters only narrow.
        $this->assertSame([], $this->balance($this->eko, ['employee' => (string) $this->rina->id]));
        $this->assertSame(['Rina Employee'], array_keys($this->balance($this->hana, ['department' => (string) $this->sales->id])));
        $this->assertSame(['Budi Supervisor', 'Hana HR', 'Siti Supervisor'], array_keys($this->balance($this->hana, ['department' => 'none'])));
    }

    public function test_the_balance_is_paginated_by_25_users(): void
    {
        foreach (range(1, 25) as $i) {
            $this->member(RoleName::Employee, ['name' => sprintf('Zz %02d', $i)]);
        }

        $component = Livewire::actingAs($this->hana)->test(Index::class);
        $this->assertCount(25, $component->viewData('users'));
        $this->assertSame(30, $component->viewData('users')->total());
    }

    public function test_the_balance_reads_only_the_allocations_of_the_users_on_the_page(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->hana)->test(Index::class)->set('employee', (string) $this->eko->id);
        $queries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'leave_request_allocations'));
        DB::disableQueryLog();

        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('`r`.`user_id` in (?)', $queries->last()['query']);
        $this->assertContains($this->eko->id, $queries->last()['bindings']);
    }

    public function test_the_balance_runs_the_same_queries_for_more_users(): void
    {
        $count = function (): int {
            Livewire::actingAs($this->hana)->test(Index::class)->set('periods', 'all');
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->hana)->test(Index::class)->set('periods', 'all');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $before = $count();

        foreach (range(1, 6) as $i) {
            $user = $this->supervisedMember(attributes: ['department_id' => $this->finance->id]);
            $day = ['2026-11-11', '2026-11-12', '2026-11-13', '2026-11-16', '2026-11-17', '2026-11-18'][$i - 1];
            $this->leaveRequest($user, 'annual', $day, $day);
            LeavePeriod::query()->create(['user_id' => $user->id, 'starts_on' => '2025-11-04', 'ends_on' => '2026-11-03', 'entitlement' => 12, 'used' => 1]);
        }

        $this->assertSame($before, $count());
    }

    public function test_departments_count_approved_requests_per_category_and_month_by_default(): void
    {
        $this->assertSame([
            'Finance-11' => ['annual' => 1, 'sick' => 0, 'other' => 0, 'overtime' => 0, 'hours' => 0],
            'none-11' => ['annual' => 0, 'sick' => 0, 'other' => 0, 'overtime' => 1, 'hours' => 4],
        ], $this->departments($this->hana));
    }

    public function test_departments_filter_by_status_year_and_department(): void
    {
        $this->assertSame([
            'Finance-11' => ['annual' => 1, 'sick' => 0, 'other' => 0, 'overtime' => 1, 'hours' => 3],
            'Sales-11' => ['annual' => 0, 'sick' => 1, 'other' => 0, 'overtime' => 0, 'hours' => 0],
            'none-11' => ['annual' => 0, 'sick' => 0, 'other' => 1, 'overtime' => 1, 'hours' => 4],
        ], $this->departments($this->hana, ['status' => 'all']));

        $this->assertSame([
            'Finance-11' => ['annual' => 0, 'sick' => 0, 'other' => 0, 'overtime' => 1, 'hours' => 3],
            'Sales-11' => ['annual' => 0, 'sick' => 1, 'other' => 0, 'overtime' => 0, 'hours' => 0],
        ], $this->departments($this->hana, ['status' => 'pending']));

        $this->assertSame(['Sales-11'], array_keys($this->departments($this->hana, ['status' => 'all', 'department' => (string) $this->sales->id])));
        $this->assertSame([], $this->departments($this->hana, ['year' => '2027']));
    }

    public function test_a_request_counts_in_the_month_it_starts(): void
    {
        $this->rawOvertime($this->eko, '2026-12-31 20:00', 6);
        $this->rawOvertime($this->eko, '2026-12-01 08:00', 3);
        $this->rawLeave($this->eko, '2026-12-30', '2027-01-04');
        $this->rawOvertime($this->eko, '2027-01-05 08:00', 2);
        $this->rawLeave($this->eko, '2027-01-06', '2027-01-06', 'other');

        $this->assertSame(
            ['annual' => 0, 'sick' => 1, 'other' => 0, 'overtime' => 2, 'hours' => 9],
            $this->departments($this->hana)['Finance-12'],
        );
        $this->assertArrayNotHasKey('Finance-1', $this->departments($this->hana));
        $this->assertSame(
            ['Finance-1' => ['annual' => 0, 'sick' => 0, 'other' => 1, 'overtime' => 1, 'hours' => 2]],
            $this->departments($this->hana, ['year' => '2027']),
        );
    }

    public function test_departments_show_only_what_one_may_see(): void
    {
        $this->assertSame(['Finance-11'], array_keys($this->departments($this->eko, ['status' => 'all'])));
        // Siti: Eko (Finance) and herself (no department).
        $this->assertSame(['Finance-11', 'none-11'], array_keys($this->departments($this->siti, ['status' => 'all'])));
        $this->assertSame(['annual' => 0, 'sick' => 0, 'other' => 0, 'overtime' => 1, 'hours' => 4], $this->departments($this->siti, ['status' => 'all'])['none-11']);
        // Budi: Rina (Sales) and Hana's rejected leave (no department).
        $this->assertSame(['Sales-11', 'none-11'], array_keys($this->departments($this->budi, ['status' => 'all'])));
    }

    public function test_the_balance_employee_filter_does_not_narrow_the_department_tab(): void
    {
        $component = Livewire::actingAs($this->hana)->test(Index::class)->set('employee', (string) $this->eko->id)->set('tab', 'departments')->set('status', 'all');

        $this->assertCount(3, $component->viewData('rows'));
    }

    public function test_departments_run_the_same_queries_for_more_data(): void
    {
        $count = function (): int {
            Livewire::actingAs($this->hana)->test(Index::class)->set('tab', 'departments');
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->hana)->test(Index::class)->set('tab', 'departments');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $before = $count();

        foreach (range(1, 6) as $i) {
            $user = $this->supervisedMember(attributes: ['department_id' => $i % 2 ? $this->finance->id : $this->sales->id]);
            $this->rawOvertime($user, '2026-0'.$i.'-02 08:00');
            $this->rawLeave($user, '2026-0'.$i.'-03', '2026-0'.$i.'-03');
        }

        $this->assertSame($before, $count());
    }

    public function test_the_page_needs_the_permission_and_refuses_forged_values(): void
    {
        $nobody = User::factory()->twoFactorEnrolled()->create();

        $this->actingAs($nobody)->get(route('recap.index'))->assertForbidden();
        Livewire::actingAs($nobody)->test(Index::class)->assertForbidden();
        Livewire::actingAs($this->hana)->test(Index::class)->set('tab', ['x'])->assertStatus(422);
        Livewire::actingAs($this->hana)->test(Index::class)->set('tab', 'secrets')->assertViewHas('shownTab', 'balance');

        $this->actingAs($this->eko)->get(route('recap.index'))->assertOk()->assertSee('Recap')->assertDontSee('Rina Employee');
        $this->actingAs($this->hana)->get(route('recap.index', ['tab' => 'departments']))->assertOk()->assertSee('Finance');
    }

    public function test_the_page_renders_in_indonesian(): void
    {
        $this->eko->forceFill(['locale' => 'id'])->save();

        $this->actingAs($this->eko)->get(route('recap.index'))->assertOk()->assertSee('Rekap')->assertSee('Disetujui, belum dipotong');
        $this->actingAs($this->eko)->get(route('history.index'))->assertOk()->assertSee('Riwayat pengajuan')->assertSee('Cuti tahunan');
    }

    public function test_statuses_other_than_approved_are_offered(): void
    {
        $component = Livewire::actingAs($this->hana)->test(Index::class)->set('tab', 'departments');

        foreach ([RequestStatus::Pending, RequestStatus::Rejected, RequestStatus::Cancelled] as $status) {
            $component->assertSeeHtml('value="'.$status->value.'"');
        }

        $component->assertSeeHtml('value="all"');
    }
}
