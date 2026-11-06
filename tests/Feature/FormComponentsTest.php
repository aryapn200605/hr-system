<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Livewire\History\Index as HistoryPage;
use App\Livewire\Recap\Index as RecapPage;
use App\Livewire\Requests\Index;
use App\Livewire\UiKitDemo;
use App\Livewire\Users\Show;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\Concerns\SeedsReportData;
use Tests\TestCase;

/**
 * Phase 15b form components: how they render in a plain form and with Livewire, the filters with several
 * values (whitelist, old links, exports) and the server searches behind the large lists.
 */
class FormComponentsTest extends TestCase
{
    use RefreshDatabase, SeedsReportData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReportData();
        view()->share('errors', new ViewErrorBag);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function keys(array $names): array
    {
        return array_map(fn (string $name) => $this->requests[$name]->routeType().'-'.$this->requests[$name]->id, $names);
    }

    private function renderBlade(string $blade, array $data = []): string
    {
        return Blade::render($blade, $data);
    }

    /**
     * The JSON a component keeps in a data-* attribute.
     */
    private function dataJson(string $html, string $attribute): mixed
    {
        $this->assertSame(1, preg_match('/'.$attribute.'="([^"]*)"/', $html, $match), $attribute);

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }

    // --- Rendering ------------------------------------------------------------------------------------------

    public function test_a_select_in_a_plain_form_renders_a_labelled_combobox_and_a_hidden_input(): void
    {
        $html = $this->renderBlade('<x-ui.select label="Status" id="s" name="status" :options="$options" value="b" hint="Pick one" />', [
            'options' => ['a' => 'Alpha', 'b' => 'Beta'],
        ]);

        $this->assertStringContainsString('<label id="s-label" for="s"', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('aria-haspopup="listbox"', $html);
        $this->assertStringContainsString('aria-controls="s-listbox"', $html);
        $this->assertStringContainsString('aria-labelledby="s-label s-value"', $html);
        $this->assertStringContainsString('aria-describedby="s-hint"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('<input type="hidden" name="status" value="b"', $html);
        // The chosen label is shown before Alpine starts.
        $this->assertMatchesRegularExpression('/id="s-value"[^>]*>Beta</', $html);
        // A short list has no search box.
        $this->assertStringNotContainsString('x-ref="search"', $html);
        $this->assertSame([['value' => 'a', 'label' => 'Alpha', 'hint' => null, 'disabled' => false, 'lang' => null], ['value' => 'b', 'label' => 'Beta', 'hint' => null, 'disabled' => false, 'lang' => null]], $this->dataJson($html, 'data-options'));
    }

    public function test_more_than_eight_options_get_a_search_box_in_a_dialog(): void
    {
        $html = $this->renderBlade('<x-ui.select label="Department" id="d" name="d" :options="$options" />', [
            'options' => collect(range(1, 9))->mapWithKeys(fn ($i) => [$i => 'Option '.$i])->all(),
        ]);

        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('x-ref="search"', $html);
        $this->assertStringContainsString('aria-autocomplete="list"', $html);
        $this->assertStringContainsString(__('No results found.'), $html);
    }

    public function test_a_multi_select_sends_a_list_and_summarises_many_values(): void
    {
        $html = $this->renderBlade('<x-ui.select label="Status" id="m" name="status" multiple :options="$options" :value="$value" />', [
            'options' => ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'],
            'value' => ['a', 'b', 'c'],
        ]);

        $this->assertStringContainsString('aria-multiselectable="true"', $html);
        $this->assertStringContainsString('name="status[]"', $html);
        $this->assertStringContainsString('data-select-all', $html);
        $this->assertMatchesRegularExpression('/id="m-value"[^>]*>3 selected</', $html);
    }

    public function test_an_error_is_linked_to_the_field(): void
    {
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag(['status' => 'Choose a status.'])));

        $html = $this->renderBlade('<x-ui.select label="Status" id="s" name="status" :options="[\'a\' => \'A\']" />');

        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="s-error"', $html);
        $this->assertStringContainsString('id="s-error"', $html);
    }

    public function test_the_date_picker_takes_today_from_the_server_in_wib(): void
    {
        // 18:30 UTC is already the next day in Jakarta.
        $this->travelTo(CarbonImmutable::parse('2026-11-06 18:30:00', 'UTC'));

        $html = $this->renderBlade('<x-ui.date-picker label="Date" id="d" name="date" />');

        $this->assertStringContainsString('data-today="2026-11-07"', $html);
        $this->assertStringContainsString('\u0022today\u0022:\u00222026-11-07\u0022', $html);
        $this->assertStringContainsString('<input type="hidden" name="date"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
    }

    public function test_the_leave_form_offers_dates_from_today_without_holidays(): void
    {
        DB::table('holidays')->insert(['date' => '2026-11-11', 'name' => 'Test holiday', 'created_at' => now(), 'updated_at' => now()]);

        $html = Livewire::actingAs($this->eko)->test(Index::class)->html();

        $this->assertStringContainsString('data-ui-date="leave-starts-on"', $html);
        $this->assertStringContainsString('\u0022min\u0022:\u00222026-11-04\u0022', $html);
        $this->assertStringContainsString('\u0022disableWeekends\u0022:true', $html);
        $this->assertStringContainsString('data-disabled-dates="[&quot;2026-11-11&quot;]"', $html);
    }

    public function test_the_hour_select_offers_only_whole_hours(): void
    {
        $html = $this->renderBlade('<x-ui.hour-select label="Hour" id="h" name="hour" value="17:00" />');
        $values = array_column($this->dataJson($html, 'data-options'), 'value');

        $this->assertCount(24, $values);
        $this->assertSame('00:00', $values[0]);
        $this->assertSame('23:00', $values[23]);
        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^([01]\d|2[0-3]):00$/', $value);
        }

        // The overtime form uses it for both ends.
        $html = Livewire::actingAs($this->eko)->test(Index::class)->html();
        $this->assertStringContainsString('data-ui-select="overtime-start-hour"', $html);
        $this->assertStringContainsString('data-ui-select="overtime-end-hour"', $html);
    }

    public function test_the_one_time_code_input_is_one_native_input(): void
    {
        $html = $this->renderBlade('<x-ui.otp-input label="Code" id="code" name="code" error="Wrong code." />');

        $this->assertSame(1, substr_count($html, '<input'));
        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('maxlength="6"', $html);
        $this->assertStringContainsString('name="code"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="code-error"', $html);
        $this->assertSame(6, substr_count($html, 'data-slot='));
    }

    // --- Livewire with arrays -------------------------------------------------------------------------------

    public function test_a_filter_with_several_values_shows_any_of_them(): void
    {
        $shown = fn ($component) => $component->viewData('rows')->getCollection()->map(fn ($row) => $row->type.'-'.$row->id)->all();

        $component = Livewire::actingAs($this->hana)->test(HistoryPage::class)->set('status', ['approved', 'rejected']);
        $this->assertEqualsCanonicalizing($this->keys(['siti-overtime', 'eko-leave', 'hana-leave']), $shown($component));

        // AND between filters: approved or rejected, and leave.
        $component->set('type', ['leave']);
        $this->assertEqualsCanonicalizing($this->keys(['eko-leave', 'hana-leave']), $shown($component));

        // One index of the list changed, as Livewire sends it when the length stays.
        $component->set('type.0', 'overtime');
        $this->assertEqualsCanonicalizing($this->keys(['siti-overtime']), $shown($component));

        $component->set('employee', [(string) $this->eko->id, (string) $this->siti->id])->set('type', [])->set('status', []);
        $this->assertEqualsCanonicalizing($this->keys(['eko-leave', 'eko-overtime', 'siti-overtime']), $shown($component));
    }

    public function test_values_outside_the_whitelist_are_dropped(): void
    {
        $component = Livewire::actingAs($this->hana)->test(HistoryPage::class)
            ->set('status', ['approved', 'payroll', 'approved'])
            ->set('category', ['sick', "annual' OR 1"])
            ->set('department', [(string) $this->sales->id, '999999', 'none', '-1']);

        $component->assertSet('status', ['approved'])
            ->assertSet('category', ['sick'])
            ->assertSet('department', [(string) $this->sales->id, 'none'])
            ->assertSet('type', ['leave']);

        // Employee IDs are kept only when shaped like an ID.
        Livewire::actingAs($this->hana)->test(HistoryPage::class)
            ->set('employee', ['abc', '0', '05', '1 OR 1=1', (string) $this->eko->id, (string) $this->eko->id])
            ->assertSet('employee', [(string) $this->eko->id]);

        // The recap drops unknown departments the same way.
        Livewire::actingAs($this->hana)->test(RecapPage::class)
            ->set('department', [(string) $this->finance->id, '999999', 'x', 'none'])
            ->assertSet('department', [(string) $this->finance->id, 'none'])
            ->set('employee', ['x', (string) $this->rina->id])
            ->assertSet('employee', [(string) $this->rina->id]);
    }

    public function test_a_forged_employee_id_never_shows_the_name_of_someone_the_user_may_not_see(): void
    {
        // Siti supervises Eko, not Rina.
        foreach ([HistoryPage::class, RecapPage::class] as $page) {
            $component = Livewire::actingAs($this->siti)->test($page)->set('employee', [(string) $this->rina->id, (string) $this->eko->id]);

            $this->assertSame([(string) $this->eko->id => 'Eko Employee'], $component->viewData('employeeLabels'), $page);
            $component->assertDontSee('Rina Employee');
        }
    }

    public function test_forged_filter_payloads_are_refused(): void
    {
        foreach ([
            ['status', 'approved'],                 // a string where a list is expected
            ['status', ['a' => 'approved']],        // not a list
            ['status', [['approved']]],             // a nested list
            ['status', [1]],                        // not a string
            ['status', array_fill(0, 51, 'x')],     // too many values
            ['status', [str_repeat('x', 101)]],     // too long
            ['status.x', 'approved'],               // not an index
            ['status.0', ['approved']],             // a list at an index
            ['from', ['2026-11-01']],               // a list where a string is expected
        ] as [$name, $value]) {
            Livewire::actingAs($this->hana)->test(HistoryPage::class)->set($name, $value)->assertStatus(422);
        }

        Livewire::actingAs($this->hana)->test(RecapPage::class)->set('department', 'none')->assertStatus(422);
        Livewire::actingAs($this->hana)->test(\App\Livewire\Users\Index::class)->set('roleFilter', [['HR Admin']])->assertStatus(422);
        Livewire::actingAs($this->siti)->test(\App\Livewire\Approvals\Index::class)->set('type', 'leave')->assertStatus(422);
    }

    public function test_an_old_link_with_one_value_still_filters(): void
    {
        Livewire::withQueryParams(['status' => 'approved', 'type' => 'leave'])->actingAs($this->hana)->test(HistoryPage::class)
            ->assertSet('status', ['approved'])
            ->assertSet('type', ['leave']);

        Livewire::withQueryParams(['role' => 'Employee', 'status' => 'ACTIVE'])->actingAs($this->hana)->test(\App\Livewire\Users\Index::class)
            ->assertSet('roleFilter', ['Employee'])
            ->assertSet('statusFilter', ['ACTIVE']);

        Livewire::withQueryParams(['employee' => (string) $this->eko->id])->actingAs($this->hana)->test(RecapPage::class)
            ->assertSet('employee', [(string) $this->eko->id]);

        Livewire::withQueryParams(['type' => 'overtime'])->actingAs($this->siti)->test(\App\Livewire\Approvals\Index::class)
            ->assertSet('type', ['overtime']);

        // And the export reads the old single value too.
        $csv = $this->actingAs($this->hana)->get(route('history.export', ['status' => 'rejected']))->streamedContent();
        $this->assertSame(1, substr_count($csv, "\r\n") - 1);
    }

    public function test_the_users_page_filters_by_several_roles_and_statuses(): void
    {
        $names = fn ($component) => $component->viewData('users')->getCollection()->pluck('name')->all();

        $component = Livewire::actingAs($this->hana)->test(\App\Livewire\Users\Index::class)->set('roleFilter', ['Employee', 'Approver']);
        $this->assertEqualsCanonicalizing(['Siti Supervisor', 'Budi Supervisor', 'Eko Employee', 'Rina Employee'], $names($component));

        $component->set('roleFilter', ['HR Admin', 'nobody'])->assertSet('roleFilter', ['HR Admin']);
        $this->assertSame(['Hana HR'], $names($component));
    }

    // --- Exports --------------------------------------------------------------------------------------------

    public function test_the_export_takes_several_values_and_keeps_to_what_the_user_may_see(): void
    {
        $lines = fn (string $csv) => count(array_filter(explode("\r\n", $csv))) - 1;

        $csv = $this->actingAs($this->hana)->get(route('history.export', ['status' => ['approved', 'pending']]))->streamedContent();
        $this->assertSame(4, $lines($csv));

        $csv = $this->actingAs($this->hana)->get(route('history.export', ['type' => ['overtime'], 'employee' => [(string) $this->eko->id, (string) $this->siti->id]]))->streamedContent();
        $this->assertSame(2, $lines($csv));

        // Siti may see Eko but not Rina: asking for both gives Eko's rows only.
        $csv = $this->actingAs($this->siti)->get(route('history.export', ['employee' => [(string) $this->eko->id, (string) $this->rina->id]]))->streamedContent();
        $this->assertStringContainsString('Eko Employee', $csv);
        $this->assertStringNotContainsString('Rina Employee', $csv);

        // An Employee asking for others gets nothing of theirs.
        $csv = $this->actingAs($this->eko)->get(route('history.export', ['employee' => [(string) $this->rina->id, (string) $this->hana->id]]))->streamedContent();
        $this->assertSame(0, $lines($csv));

        // The page's export link carries the lists.
        $url = Livewire::actingAs($this->hana)->test(HistoryPage::class)->set('status', ['approved', 'pending'])->viewData('exportUrl');
        $this->assertStringContainsString('status%5B0%5D=approved&status%5B1%5D=pending', $url);

        // The audit row records the lists.
        $this->assertSame(['status' => ['approved', 'pending']], json_decode(DB::table('audit_logs')->where('event', 'report.exported')->orderBy('id')->value('metadata'), true)['filters'] ?? null);
    }

    public function test_the_department_recap_export_takes_several_departments(): void
    {
        $csv = $this->actingAs($this->hana)->get(route('recap.departments.export', ['department' => [(string) $this->finance->id, 'none'], 'status' => 'all']))->streamedContent();

        $this->assertStringContainsString('Finance', $csv);
        $this->assertStringNotContainsString('Sales', $csv);
    }

    // --- Server search --------------------------------------------------------------------------------------

    public function test_the_employee_search_returns_only_people_the_user_may_see(): void
    {
        $search = fn ($user, string $term = '', string $page = HistoryPage::class) => collect(Livewire::actingAs($user)->test($page)->call('searchEmployees', $term)->effects['returns'][0] ?? [])->pluck('label')->all();

        // An Employee sees only their own requests and gets nobody.
        $this->assertSame([], $search($this->eko));
        $this->assertSame([], $search($this->eko, 'Rina'));
        // An Approver gets themself and their subordinates.
        $this->assertSame(['Eko Employee', 'Siti Supervisor'], $search($this->siti));
        $this->assertSame([], $search($this->siti, 'Rina'));
        // HR Admin gets everyone, by name or username, and the recap searches the same way.
        $this->assertSame(['Rina Employee'], $search($this->hana, 'rina'));
        $this->assertSame(['Rina Employee'], $search($this->hana, $this->rina->username, RecapPage::class));
        $this->assertSame([], $search($this->hana, '%'));
        $this->assertCount(5, $search($this->hana));

        // The page itself lists no names but those of the chosen people.
        $html = Livewire::actingAs($this->hana)->test(HistoryPage::class)->set('employee', [(string) $this->eko->id])->html();
        $this->assertStringContainsString('Eko Employee', $html);
        $this->assertStringNotContainsString('Budi Supervisor', $html);
    }

    public function test_the_employee_search_is_limited(): void
    {
        foreach (range(1, 30) as $i) {
            $this->member(RoleName::Employee, ['name' => sprintf('Zed %02d', $i)]);
        }

        $found = Livewire::actingAs($this->hana)->test(HistoryPage::class)->call('searchEmployees', 'Zed')->effects['returns'][0];

        $this->assertCount(HistoryPage::SEARCH_LIMIT, $found);
        $this->assertSame('Zed 01', $found[0]['label']);
    }

    public function test_the_supervisor_searches_need_the_right_to_manage_supervisors(): void
    {
        $page = Show::class;

        $found = Livewire::actingAs($this->hana)->test($page, ['user' => (string) $this->eko->id])->call('searchCandidates', '')->effects['returns'][0];
        // Siti supervises Eko already, so only Budi is offered.
        $this->assertSame(['Budi Supervisor ('.$this->budi->username.')'], array_column($found, 'label'));

        $found = Livewire::actingAs($this->hana)->test($page, ['user' => (string) $this->siti->id])->call('searchTargets', 'budi')->effects['returns'][0];
        $this->assertSame([(string) $this->budi->id], array_column($found, 'value'));

        // At most SEARCH_LIMIT Approvers per search.
        foreach (range(1, 25) as $i) {
            $this->member(RoleName::Approver, ['name' => sprintf('Approver %02d', $i)]);
        }
        $found = Livewire::actingAs($this->hana)->test($page, ['user' => (string) $this->eko->id])->call('searchCandidates', 'Approver')->effects['returns'][0];
        $this->assertCount($page::SEARCH_LIMIT, $found);
        $this->assertStringStartsWith('Approver 01 (', $found[0]['label']);

        // Not an Approver: nobody to move subordinates to.
        $this->assertSame([], Livewire::actingAs($this->hana)->test($page, ['user' => (string) $this->eko->id])->call('searchTargets', '')->effects['returns'][0]);

        // Without the permission, both searches are refused.
        $viewer = $this->member(RoleName::Employee, ['name' => 'Viewer']);
        $viewer->givePermissionTo(PermissionName::UsersView->value);
        foreach (['searchCandidates', 'searchTargets'] as $method) {
            $component = Livewire::actingAs($viewer)->test($page, ['user' => (string) $this->siti->id]);
            $component->call($method, '')->assertForbidden();
        }

        // The dialogs list nobody in the page itself.
        $html = Livewire::actingAs($this->hana)->test($page, ['user' => (string) $this->eko->id])->html();
        $this->assertStringNotContainsString('Budi Supervisor', $html);
    }

    // --- Gallery --------------------------------------------------------------------------------------------

    public function test_the_livewire_gallery_follows_values_set_by_the_server_and_exists_only_locally(): void
    {
        $this->app['env'] = 'local';

        Livewire::actingAs($this->eko)->test(UiKitDemo::class)
            ->set('statuses', ['pending'])
            ->assertSet('statuses', ['pending'])
            ->call('setFromServer')
            ->assertSet('people', ['12', '45', '78'])
            ->assertSee('Gita Pratama')
            ->set('people', 'x')
            ->assertStatus(422);

        $this->assertCount(UiKitDemo::SEARCH_LIMIT, Livewire::actingAs($this->eko)->test(UiKitDemo::class)->call('searchPeople', '')->effects['returns'][0]);

        $this->app['env'] = 'testing';
        Livewire::actingAs($this->eko)->test(UiKitDemo::class)->assertNotFound();
    }
}
