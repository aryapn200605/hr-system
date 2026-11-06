<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Livewire\History\Index;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsReportData;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use RefreshDatabase, SeedsReportData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReportData();
    }

    /**
     * The "type-id" keys of the rows the page shows to this user, in order.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function shown(User $user, array $filters = []): array
    {
        $component = Livewire::actingAs($user)->test(Index::class);

        foreach ($filters as $name => $value) {
            // Since Phase 15b these filters hold lists; one value is a list of one.
            $component->set($name, in_array($name, ['employee', 'department', 'type', 'category', 'status'], true) ? [$value] : $value);
        }

        return $component->viewData('rows')->getCollection()->map(fn ($row) => $row->type.'-'.$row->id)->all();
    }

    private function key(string $name): string
    {
        $request = $this->requests[$name];

        return $request->routeType().'-'.$request->id;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function keys(array $names): array
    {
        return array_map($this->key(...), $names);
    }

    public function test_an_employee_sees_only_their_own_requests(): void
    {
        $this->assertSame($this->keys(['eko-overtime', 'eko-leave']), $this->shown($this->eko));
        $this->assertSame($this->keys(['rina-leave']), $this->shown($this->rina));
    }

    public function test_an_approver_sees_their_own_and_their_subordinates_requests(): void
    {
        $this->assertSame($this->keys(['siti-overtime', 'eko-overtime', 'eko-leave']), $this->shown($this->siti));
        // Budi supervises Rina and Hana, and has no request of his own.
        $this->assertSame($this->keys(['hana-leave', 'rina-leave']), $this->shown($this->budi));
    }

    public function test_hr_admin_sees_every_request_newest_first(): void
    {
        $this->assertSame(
            $this->keys(['hana-leave', 'siti-overtime', 'rina-leave', 'eko-overtime', 'eko-leave']),
            $this->shown($this->hana),
        );
    }

    public function test_the_relation_is_read_as_it_is_now(): void
    {
        DB::table('supervisor_user')->where('supervisor_id', $this->siti->id)->delete();

        $this->assertSame($this->keys(['siti-overtime']), $this->shown($this->siti));
    }

    public function test_a_supervisor_without_the_decide_permission_sees_only_their_own(): void
    {
        $this->siti->roles()->first()->revokePermissionTo('requests.decide');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame($this->keys(['siti-overtime']), $this->shown($this->siti->fresh()));
    }

    public function test_the_page_needs_the_permission_by_url_and_by_livewire(): void
    {
        $nobody = User::factory()->twoFactorEnrolled()->create();

        $this->actingAs($nobody)->get(route('history.index'))->assertForbidden();
        Livewire::actingAs($nobody)->test(Index::class)->assertForbidden();
        $this->actingAs($this->eko)->get(route('history.index'))->assertOk()->assertSee('Request history');

        auth()->logout();
        $this->get(route('history.index'))->assertRedirect(route('login'));
    }

    public function test_the_menu_shows_history_and_recap_to_every_role(): void
    {
        foreach ([$this->eko, $this->siti, $this->hana] as $user) {
            $this->actingAs($user)->get(route('dashboard'))
                ->assertSee(route('history.index'))
                ->assertSee(route('recap.index'));
        }
    }

    public function test_a_forged_employee_filter_cannot_widen_what_an_employee_sees(): void
    {
        $component = Livewire::actingAs($this->eko)->test(Index::class)->set('employee', [(string) $this->rina->id]);

        $this->assertSame([], $component->viewData('rows')->getCollection()->all());
        $component->assertDontSee('Rina Employee')->assertDontSeeHtml('id="filter-employee"')->assertDontSeeHtml('id="filter-department"');
        $this->assertFalse($component->viewData('seesOthers'));

        // An Approver is offered only the people they may see.
        $this->assertSame(['Eko Employee', 'Siti Supervisor'], array_column(Livewire::actingAs($this->siti)->test(Index::class)->call('searchEmployees', '')->effects['returns'][0], 'label'));

        $this->assertSame([], $this->shown($this->siti, ['employee' => (string) $this->rina->id]));
    }

    public function test_anything_but_a_string_is_refused(): void
    {
        Livewire::actingAs($this->hana)->test(Index::class)->set('employee', 'x')->assertStatus(422);
        Livewire::actingAs($this->hana)->test(Index::class)->set('from', ['2026-11-01'])->assertStatus(422);
    }

    public function test_malformed_filters_are_ignored(): void
    {
        $all = $this->shown($this->hana);

        foreach (['employee' => '1 OR 1=1', 'department' => '-1', 'type' => 'payroll', 'status' => 'approved;', 'from' => '2026-11-31', 'to' => 'tomorrow'] as $name => $value) {
            $this->assertSame($all, $this->shown($this->hana, [$name => $value]), $name);
        }

        // The page switches to leave for any category; a malformed one then filters nothing more.
        $this->assertSame($this->shown($this->hana, ['type' => 'leave']), $this->shown($this->hana, ['category' => "annual'"]));
        $this->assertSame([], ReportFilters::from(['category' => "annual' OR 1"])->categories);
        $this->assertSame([], ReportFilters::from(['employee' => '99999999999999999999'])->employees);
        // A day that does not exist would roll over (31 November is 1 December) and hide November.
        $this->assertNull(ReportFilters::from(['from' => '2026-11-31'])->from);
        $this->assertSame([], ReportFilters::from(['type' => 'payroll'])->types);
    }

    public function test_filters_by_employee_department_type_category_and_status(): void
    {
        $this->assertSame($this->keys(['eko-overtime', 'eko-leave']), $this->shown($this->hana, ['employee' => (string) $this->eko->id]));
        $this->assertSame($this->keys(['rina-leave']), $this->shown($this->hana, ['department' => (string) $this->sales->id]));
        $this->assertSame($this->keys(['hana-leave', 'siti-overtime']), $this->shown($this->hana, ['department' => 'none']));
        $this->assertSame($this->keys(['siti-overtime', 'eko-overtime']), $this->shown($this->hana, ['type' => 'overtime']));
        $this->assertSame($this->keys(['hana-leave', 'rina-leave', 'eko-leave']), $this->shown($this->hana, ['type' => 'leave']));
        $this->assertSame($this->keys(['rina-leave']), $this->shown($this->hana, ['category' => 'sick']));
        $this->assertSame($this->keys(['siti-overtime', 'eko-leave']), $this->shown($this->hana, ['status' => 'approved']));
        $this->assertSame($this->keys(['rina-leave', 'eko-overtime']), $this->shown($this->hana, ['status' => 'pending']));
        $this->assertSame($this->keys(['hana-leave']), $this->shown($this->hana, ['status' => 'rejected']));
    }

    public function test_a_category_selects_leave_and_overtime_clears_the_category(): void
    {
        $component = Livewire::actingAs($this->hana)->test(Index::class)->set('category', ['annual']);
        $component->assertSet('type', ['leave']);

        $component->set('type', ['overtime'])->assertSet('category', []);
        $this->assertSame($this->keys(['siti-overtime', 'eko-overtime']), $component->viewData('rows')->getCollection()->map(fn ($row) => $row->type.'-'.$row->id)->all());
    }

    public function test_the_date_range_lists_requests_that_overlap_it(): void
    {
        // Eko's leave runs 9–10 Nov; it overlaps a range that only touches its last day.
        $this->assertSame($this->keys(['eko-leave']), $this->shown($this->hana, ['from' => '2026-11-10', 'to' => '2026-11-12']));
        $this->assertSame($this->keys(['eko-leave']), $this->shown($this->hana, ['from' => '2026-11-08', 'to' => '2026-11-09']));
        $this->assertSame([], $this->shown($this->hana, ['from' => '2026-11-11', 'to' => '2026-11-12']));
        // Either end open.
        $this->assertSame($this->keys(['hana-leave', 'rina-leave']), $this->shown($this->hana, ['from' => '2026-11-11']));
        $this->assertSame($this->keys(['siti-overtime', 'eko-overtime']), $this->shown($this->hana, ['to' => '2026-11-06']));
        // A range typed the wrong way round.
        $this->assertSame($this->keys(['eko-leave']), $this->shown($this->hana, ['from' => '2026-11-12', 'to' => '2026-11-10']));
    }

    public function test_overtime_across_midnight_overlaps_the_date_it_ends_on(): void
    {
        $late = $this->rawOvertime($this->eko, '2026-11-12 20:00', 6);

        $this->assertContains('overtime-'.$late->id, $this->shown($this->hana, ['from' => '2026-11-13', 'to' => '2026-11-13']));
    }

    public function test_it_shows_25_per_page(): void
    {
        foreach (range(1, 30) as $day) {
            $this->rawOvertime($this->rina, '2026-12-01 08:00', 1, RequestStatus::Pending);
        }

        $component = Livewire::actingAs($this->hana)->test(Index::class);
        $this->assertCount(25, $component->viewData('rows')->getCollection());
        $this->assertSame(35, $component->viewData('rows')->total());

        $first = $component->viewData('rows')->getCollection()->pluck('id')->all();
        $component->call('nextPage');
        $this->assertCount(10, $component->viewData('rows')->getCollection());

        // The 30 rows share one submission time: type and ID break the tie, so no row repeats or goes missing.
        $ids = array_merge($first, $component->viewData('rows')->getCollection()->filter(fn ($row) => $row->type === 'overtime' && $row->userId === $this->rina->id)->pluck('id')->all());
        $same = OvertimeRequest::query()->where('user_id', $this->rina->id)->orderByDesc('id')->pluck('id')->all();
        $this->assertSame($same, array_slice($ids, 0, 30));
    }

    public function test_rows_link_only_to_detail_pages_the_user_may_open(): void
    {
        $eko = Livewire::actingAs($this->eko)->test(Index::class);
        $this->assertSame([
            $this->key('eko-overtime') => route('requests.show', ['type' => 'overtime', 'id' => $this->requests['eko-overtime']->id]),
            $this->key('eko-leave') => route('requests.show', ['type' => 'leave', 'id' => $this->requests['eko-leave']->id]),
        ], $eko->viewData('links'));

        // Siti: her own request on My requests, Eko's on Approvals (she may decide them, decided or not).
        $siti = Livewire::actingAs($this->siti)->test(Index::class)->viewData('links');
        $this->assertSame(route('requests.show', ['type' => 'overtime', 'id' => $this->requests['siti-overtime']->id]), $siti[$this->key('siti-overtime')]);
        $this->assertSame(route('approvals.show', ['type' => 'leave', 'id' => $this->requests['eko-leave']->id]), $siti[$this->key('eko-leave')]);
        $this->assertSame(route('approvals.show', ['type' => 'overtime', 'id' => $this->requests['eko-overtime']->id]), $siti[$this->key('eko-overtime')]);

        // Hana decides Approvers' requests and her own detail page is hers; Employees' requests open read-only in history.
        $hana = Livewire::actingAs($this->hana)->test(Index::class)->viewData('links');
        $this->assertSame(route('approvals.show', ['type' => 'overtime', 'id' => $this->requests['siti-overtime']->id]), $hana[$this->key('siti-overtime')]);
        $this->assertSame(route('requests.show', ['type' => 'leave', 'id' => $this->requests['hana-leave']->id]), $hana[$this->key('hana-leave')]);
        $this->assertSame(route('history.show', ['type' => 'leave', 'id' => $this->requests['eko-leave']->id]), $hana[$this->key('eko-leave')]);
        $this->assertSame(route('history.show', ['type' => 'leave', 'id' => $this->requests['rina-leave']->id]), $hana[$this->key('rina-leave')]);

        // Every link opens: the existing pages agree.
        foreach ([[$this->eko, $eko->viewData('links')], [$this->siti, $siti], [$this->hana, $hana]] as [$user, $links]) {
            foreach ($links as $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_a_removed_link_hides_the_rows_and_a_new_link_shows_them(): void
    {
        DB::table('supervisor_user')->where('supervisor_id', $this->siti->id)->delete();
        $this->supervise($this->siti, $this->rina);

        $links = Livewire::actingAs($this->siti)->test(Index::class)->viewData('links');

        // Siti decided Eko's leave, so she still sees it in Approvals, but Eko's rows are no longer hers to list.
        $this->assertArrayNotHasKey($this->key('eko-leave'), $links);
        $this->assertArrayHasKey($this->key('rina-leave'), $links);
    }

    public function test_whoever_decided_keeps_the_link_after_losing_the_authority(): void
    {
        // Siti stops being an Approver (Budi takes over Eko first), so Hana no longer decides her requests.
        $this->supervise($this->budi, $this->eko);
        $this->siti->changeRole(RoleName::Employee, $this->hana);
        $this->supervise($this->budi, $this->siti);

        $links = Livewire::actingAs($this->hana)->test(Index::class)->viewData('links');

        $this->assertArrayHasKey($this->key('siti-overtime'), $links);
        $this->actingAs($this->hana)->get($links[$this->key('siti-overtime')])->assertOk();
    }

    public function test_links_follow_the_permissions_not_only_the_rows(): void
    {
        // A user who may see everything but neither submit nor decide: every row opens read-only, their own too.
        $viewer = User::factory()->twoFactorEnrolled()->create(['name' => 'Vera Viewer']);
        $viewer->givePermissionTo('requests.view-all');
        $own = $this->rawOvertime($viewer, '2026-12-07 08:00');
        $decided = $this->rawOvertime($this->rina, '2026-12-08 08:00', 2, RequestStatus::Approved, $viewer);

        $component = Livewire::actingAs($viewer)->test(Index::class);
        $shown = $component->viewData('rows')->getCollection()->map(fn ($row) => $row->type.'-'.$row->id)->all();

        $this->assertContains('overtime-'.$own->id, $shown);
        $this->assertContains('overtime-'.$decided->id, $shown);
        $links = $component->viewData('links');
        $this->assertSame(route('history.show', ['type' => 'overtime', 'id' => $own->id]), $links['overtime-'.$own->id]);
        $this->assertSame(route('history.show', ['type' => 'overtime', 'id' => $decided->id]), $links['overtime-'.$decided->id]);
        $this->assertSame([], array_filter($links, fn (string $url) => ! str_contains($url, '/history/')));
    }

    public function test_the_page_shows_no_reason_text(): void
    {
        Livewire::actingAs($this->hana)->test(Index::class)
            ->assertSee('Eko Employee')
            ->assertSee('Finance')
            ->assertDontSee(self::REASON)
            ->assertDontSee('Not this week, sorry.');
    }

    public function test_the_page_runs_the_same_queries_for_more_data(): void
    {
        $count = function (): int {
            Livewire::actingAs($this->hana)->test(Index::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->hana)->test(Index::class)->set('status', []);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $before = $count();

        foreach (range(1, 6) as $i) {
            $user = $this->supervisedMember(attributes: ['department_id' => $i % 2 ? $this->finance->id : null]);
            $this->rawOvertime($user, '2026-12-0'.$i.' 08:00', 2, RequestStatus::Approved, $this->siti);
            $this->rawLeave($user, '2026-12-1'.$i, '2026-12-1'.$i);
        }

        $this->assertSame($before, $count());
    }
}
