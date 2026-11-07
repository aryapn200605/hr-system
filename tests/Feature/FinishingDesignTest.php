<?php

namespace Tests\Feature;

use App\Enums\LoginResult;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Services\Requests\Decisions;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Supervisors;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Phase 15e: tables as cards on phones, empty states (filtered against really empty, calls to action that the
 * role may use), live announcements and the markup checks of the accessibility audit that a test can make.
 * The error pages are in ErrorPagesTest.
 */
class FinishingDesignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $approver;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = $this->userWithRole(RoleName::HrAdmin, ['name' => 'Hana Admin']);
        $this->approver = $this->userWithRole(RoleName::Approver, ['name' => 'Ari Approver']);
        $this->employee = $this->userWithRole(RoleName::Employee, ['name' => 'Eka Employee']);
    }

    private function userWithRole(RoleName $role, array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create(['status' => UserStatus::Active] + $attributes);
    }

    /**
     * Rows for every table: a department, a holiday, a leave request (approved, so it has allocations and a
     * ledger entry), an overtime request (pending), a balance adjustment and login history.
     *
     * @return array{leave: int, overtime: int}
     */
    private function scenario(): array
    {
        $department = Department::create(['name' => 'Finance', 'is_active' => true]);
        $this->employee->forceFill(['department_id' => $department->id])->save();
        app(Supervisors::class)->attach($this->admin, $this->employee, (string) $this->approver->id);
        Holiday::create(['date' => now()->year.'-12-25', 'name' => 'Christmas Day']);

        $monday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY);
        $leave = app(LeaveRequests::class)->submit($this->employee, ['category' => 'annual', 'starts_on' => $monday->toDateString(), 'ends_on' => $monday->addDay()->toDateString(), 'reason' => 'Family visit out of town.']);
        app(Decisions::class)->approve($this->approver, 'leave', $leave->id);
        $overtime = app(OvertimeRequests::class)->submit($this->employee, ['start_at' => $monday->addDays(2)->toDateString().' 18:00', 'end_at' => $monday->addDays(2)->toDateString().' 20:00', 'reason' => 'Release night support.']);
        app(LeaveBalance::class)->adjust($this->admin, $this->employee, '1', 'Correction after the audit.');

        foreach ([LoginResult::Success, LoginResult::FailedTotp] as $result) {
            LoginHistory::create(['user_id' => $this->employee->id, 'result' => $result->value, 'ip_address' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36', 'occurred_at' => now()]);
        }

        return ['leave' => $leave->id, 'overtime' => $overtime->id];
    }

    private function dom(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    private static function clean(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * Every card table of the page: each body cell has the label of its column, or is the row's title, menu or
     * actions, or spans the row; each cell's content sits in one div[data-cell].
     *
     * @return list<string> the captions of the tables checked
     */
    private function assertCardTables(string $html, string $page): array
    {
        $captions = [];

        foreach ($this->dom($html)->querySelectorAll('table.table-cards') as $table) {
            $caption = self::clean($table->querySelector('caption')?->textContent);
            $captions[] = $caption;
            $headers = array_map(fn ($th) => self::clean($th->textContent), iterator_to_array($table->querySelectorAll('thead th')));
            $this->assertNotEmpty($headers, "$page / $caption: header row");
            $rows = iterator_to_array($table->querySelectorAll('tbody > tr'));
            $this->assertNotEmpty($rows, "$page / $caption: no row to check");

            foreach ($rows as $row) {
                $index = 0;

                foreach ($row->children as $cell) {
                    $span = (int) ($cell->getAttribute('colspan') ?: 1);
                    $where = "$page / $caption / column ".($index + 1);

                    if ($cell->tagName === 'TD') {
                        $wrappers = array_filter(iterator_to_array($cell->children), fn ($child) => $child->hasAttribute('data-cell'));
                        $this->assertCount(1, $wrappers, "$where: one div[data-cell]");
                        $this->assertSame(1, $cell->childElementCount, "$where: the wrapper holds everything");
                    }

                    if ($cell->hasAttribute('data-label')) {
                        $this->assertSame($headers[$index] ?? null, $cell->getAttribute('data-label'), "$where: label is the column header");
                    } elseif ($span === 1) {
                        $this->assertContains($cell->getAttribute('data-card'), ['title', 'menu', 'actions'], "$where: a cell without a label must be the title, menu or actions");
                    }

                    $index += $span;
                }
            }
        }

        return $captions;
    }

    // ---- Tables as cards ----------------------------------------------------------------------------------

    public function test_every_table_of_the_application_turns_into_labelled_cards(): void
    {
        $ids = $this->scenario();
        $seen = [];

        $pages = [
            [$this->admin, route('users.index')],
            [$this->admin, route('departments.index')],
            [$this->admin, route('holidays.index')],
            [$this->admin, route('users.leave-balance', $this->employee->id)],
            [$this->admin, route('history.index')],
            [$this->admin, route('recap.index')],
            [$this->admin, route('recap.index', ['tab' => 'departments', 'status' => 'all'])],
            [$this->employee, route('requests.index')],
            [$this->employee, route('requests.index', ['type' => 'overtime'])],
            [$this->employee, route('requests.show', ['type' => 'leave', 'id' => $ids['leave']])],
            [$this->employee, route('profile.login-history')],
            [$this->approver, route('approvals.index')],
            [$this->approver, route('approvals.index', ['tab' => 'decided'])],
            [$this->approver, route('approvals.show', ['type' => 'overtime', 'id' => $ids['overtime']])],
        ];

        foreach ($pages as [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            array_push($seen, ...$this->assertCardTables($html, $url));
        }

        // Every table of the application was reached (the gallery's is checked below).
        foreach (['Users', 'Departments', 'Holidays', 'Periods', 'Ledger', 'Request history', 'Leave balance per employee and period', 'Requests per department and month', 'Leave requests', 'Overtime requests', 'Leave balance used per period', 'Your login attempts, newest first', 'Requests waiting for me', 'Requests decided by me', 'Overtime hours per date'] as $caption) {
            $this->assertContains($caption, $seen);
        }
    }

    public function test_no_table_view_has_a_cell_without_a_label_or_a_role_in_the_card(): void
    {
        $views = collect(glob(resource_path('views/livewire/{*,*/*,*/*/*}.blade.php'), GLOB_BRACE))
            ->push(resource_path('views/ui-kit.blade.php'))
            ->filter(fn ($file) => str_contains(file_get_contents($file), '<x-ui.table'));

        $this->assertGreaterThanOrEqual(12, $views->count());

        foreach ($views as $file) {
            preg_match_all('#<td\b(?:[^>"]|"[^"]*")*>#', file_get_contents($file), $cells);

            foreach ($cells[0] as $cell) {
                $this->assertMatchesRegularExpression('#data-label=|data-card="(title|menu|actions)"|colspan=#', $cell, basename($file).': '.$cell);
            }
        }
    }

    public function test_the_card_layout_applies_below_1024_px_with_44_px_targets(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Screen only: a printed page keeps the plain table.
        $this->assertMatchesRegularExpression('#@media screen and \(width < 64rem\) \{\s*\.table-cards,\s*\.table-cards > tbody \{\s*display: block;#', $css);
        $this->assertMatchesRegularExpression('#\.table-cards > thead \{\s*display: none;#', $css);
        $this->assertMatchesRegularExpression("#td\[data-label\]::before \{\s*content: attr\(data-label\);#", $css);
        $this->assertMatchesRegularExpression("#\.table-cards \[data-card='menu'\] button,\s*\.table-cards \[data-card='actions'\] :is\(a, button\) \{\s*min-height: 2\.75rem;\s*min-width: 2\.75rem;#", $css);
        // Not inside a cascade layer: it must win over the table's utility classes.
        $this->assertDoesNotMatchRegularExpression('#@layer [a-z]+ \{(?:(?!\n\}).)*table-cards#s', $css);
    }

    public function test_the_table_wraps_each_cell_and_can_stay_a_table(): void
    {
        $html = Blade::render('<x-ui.table caption="People"><x-slot:head><tr><th>Name</th><th>Since</th></tr></x-slot:head><tr><td data-card="title">Rina <b>K.</b></td><td data-label="Since">1 Jan – 2 Feb</td></tr></x-ui.table>');
        $this->assertStringContainsString('class="w-full caption-bottom', $html);
        $this->assertStringContainsString('table-cards', $html);
        $this->assertStringContainsString('<td data-card="title"><div data-cell>Rina <b>K.</b></div></td>', $html);
        $this->assertStringContainsString('<td data-label="Since"><div data-cell>1 Jan – 2 Feb</div></td>', $html);

        $plain = Blade::render('<x-ui.table caption="People" :cards="false"><x-slot:head><tr><th>Name</th></tr></x-slot:head><tr><td>Rina</td></tr></x-ui.table>');
        $this->assertStringNotContainsString('table-cards', $plain);
        $this->assertStringContainsString('<td>Rina</td>', $plain);
    }

    public function test_row_menus_and_card_actions_are_marked_for_their_place_in_the_card(): void
    {
        $this->scenario();

        $users = $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<td class="text-right" data-card="menu"><div data-cell>\s*<div\s+class="inline-block"\s+x-data="\{ open: false#', $users);

        $requests = $this->actingAs($this->employee)->get(route('requests.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<td class="text-right" data-card="actions"><div data-cell>(?:<!--\[if BLOCK\]><!\[endif\]-->)?\s*<a href="[^"]+/requests/leave/\d+"#', $requests);
    }

    // ---- Empty states -------------------------------------------------------------------------------------

    public function test_my_requests_tells_a_filtered_list_from_an_empty_one(): void
    {
        app(Supervisors::class)->attach($this->admin, $this->employee, (string) $this->approver->id);

        $none = $this->actingAs($this->employee)->get(route('requests.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="none"[^>]*>.*?No requests found.*?Requests you submit appear here\..*?wire:click="openLeave"#s', $none);

        $filtered = $this->actingAs($this->employee)->get(route('requests.index', ['status' => 'approved']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="filtered"[^>]*>.*?No requests with this status.*?wire:click="\$set\(\'statusFilter\', \'\'\)"#s', $filtered);
        $this->assertStringNotContainsString('data-empty="none"', $filtered);

        // Without an approval path: the explanation, and no button that could not be used.
        $alone = $this->userWithRole(RoleName::Employee);
        $html = $this->actingAs($alone)->get(route('requests.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="none"[^>]*>.*?You can submit requests once you have an approval path\.#s', $html);
        $this->assertDoesNotMatchRegularExpression('#data-empty="none"(?:(?!</div>\s*</div>).)*wire:click="openLeave"#s', $html);
    }

    public function test_approvals_tell_a_filtered_list_from_an_empty_one(): void
    {
        $waiting = $this->actingAs($this->approver)->get(route('approvals.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="none"[^>]*>.*?Nothing waits for you.*?wire:click="\$set\(\'tab\', \'decided\'\)"#s', $waiting);

        $filtered = $this->actingAs($this->approver)->get(route('approvals.index', ['type' => ['overtime']]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="filtered"[^>]*>.*?No requests of this type.*?wire:click="\$set\(\'type\', \[\]\)"#s', $filtered);

        $decided = $this->actingAs($this->approver)->get(route('approvals.index', ['tab' => 'decided']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="none"[^>]*>.*?No decisions yet#s', $decided);
        $this->assertStringNotContainsString('data-show-decided', $decided);
    }

    public function test_recap_and_history_offer_clear_filters_only_when_filtered(): void
    {
        $department = Department::create(['name' => 'Empty team', 'is_active' => true]);

        $filtered = $this->actingAs($this->admin)->get(route('recap.index', ['department' => [(string) $department->id]]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="filtered"[^>]*>.*?No employees found.*?data-clear-filters#s', $filtered);

        $departments = $this->actingAs($this->admin)->get(route('recap.index', ['tab' => 'departments']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="none"[^>]*>.*?No approved requests in '.now()->year.'.*?per department and month#s', $departments);
        $this->assertDoesNotMatchRegularExpression('#data-empty="none"(?:(?!</div>\s*</div>).)*data-clear-filters#s', $departments);

        $history = $this->actingAs($this->employee)->get(route('history.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-empty="none"[^>]*>.*?Requests appear here once they are submitted\.#s', $history);
        $this->assertDoesNotMatchRegularExpression('#data-empty="none"(?:(?!</div>\s*</div>).)*clearFilters#s', $history);
    }

    public function test_the_ledger_explains_an_empty_list(): void
    {
        $user = User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create(['status' => UserStatus::Active]);
        $html = $this->actingAs($this->admin)->get(route('users.leave-balance', $user->id))->assertOk()->getContent();

        if (str_contains($html, 'No entries yet')) {
            $this->assertStringContainsString('Every change to this balance is listed here, with who made it and why.', $html);
        } else {
            $this->assertStringContainsString('data-label="Reason"', $html);
        }
    }

    public function test_empty_states_offer_admin_actions_only_on_pages_the_role_reaches(): void
    {
        // Departments and holidays offer "Add …" in their empty states; both pages are HR Admin only.
        $this->actingAs($this->admin)->get(route('departments.index'))->assertOk()->assertSee('Add department');
        $this->actingAs($this->approver)->get(route('departments.index'))->assertForbidden();
        $this->actingAs($this->employee)->get(route('holidays.index'))->assertForbidden();
        // An Employee's dashboard and history never show an admin action.
        foreach ([route('dashboard'), route('history.index'), route('recap.index')] as $url) {
            $html = $this->actingAs($this->employee)->get($url)->assertOk()->getContent();
            foreach (['openCreate', 'Add department', 'Add holiday', 'Create user', 'openAdjust'] as $action) {
                $this->assertStringNotContainsString($action, $html, $url);
            }
        }
    }

    // ---- Announcements and markup -------------------------------------------------------------------------

    public function test_list_results_errors_and_toasts_are_announced(): void
    {
        $html = $this->actingAs($this->admin)->get(route('history.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-announcer></div>#', $html);
        $this->assertStringContainsString("Livewire.hook('morphed'", $html);
        $this->assertMatchesRegularExpression('#role="status"\s+aria-live="polite"\s+data-toasts#', $html);
        $this->assertStringContainsString('data-announce>', Blade::render('<x-ui.empty-state title="None" />'));

        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag(['name' => 'The name is required.'])));
        $error = Blade::render('<x-ui.error for="name" />');
        $this->assertStringContainsString('role="alert">The name is required.</p>', $error);
        $this->assertStringContainsString('role="alert"', Blade::render('<x-ui.alert tone="danger">Failed</x-ui.alert>'));
        $this->assertStringContainsString('role="status"', Blade::render('<x-ui.alert tone="warning">Note</x-ui.alert>'));
    }

    public function test_every_animation_stops_when_reduced_motion_is_asked(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('#@media \(prefers-reduced-motion: reduce\) \{\s*\*,\s*::before,\s*::after \{\s*animation-duration: 0\.01ms !important;\s*animation-iteration-count: 1 !important;\s*transition-duration: 0\.01ms !important;#', $css);
    }

    public function test_a_dialog_gives_the_focus_back_to_the_control_that_opened_it(): void
    {
        // The trap's own return would land on <body> when the opening button was disabled by its Livewire request.
        $html = Blade::render('<x-ui.modal name="demo" title="Demo">Body</x-ui.modal>');
        $this->assertStringContainsString('x-trap.noscroll.noreturn="open"', $html);
        $this->assertStringContainsString("x-init=\"\$watch('open', (value) => value || \$nextTick(() => window.uiRestoreFocus?.(back)))\"", $html);
        $this->assertStringContainsString('if (! open) back = window.uiLastFocus ?? document.activeElement; open = true', $html);

        $js = file_get_contents(resource_path('js/ui.js'));
        $this->assertStringContainsString("if (! event.target.closest?.('[role=dialog][aria-modal=true]')) window.uiLastFocus = event.target", $js);
        $this->assertStringContainsString('if (menu) target = document.querySelector(`[aria-controls="${menu.id}"]`)', $js);
        $this->assertStringContainsString('if (target.disabled && attempts++ < 20) return setTimeout(tryFocus, 50)', $js);
    }

    public function test_a_printed_page_shows_its_content_without_the_application_chrome(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('#@media print \{(?:(?!\n\}).)*\[data-sidebar-desktop\],(?:(?!\n\}).)*\[data-content\] > header,(?:(?!\n\}).)*\[data-toolbar\],(?:(?!\n\}).)*\[data-card=\'menu\'\],(?:(?!\n\}).)*display: none !important;#s', $css);
        $this->assertMatchesRegularExpression("#@media print \{(?:(?!\n\}).)*\[role='region'\] \{\s*overflow: visible !important;#s", $css);
    }

    public function test_pages_follow_the_markup_rules_of_the_audit(): void
    {
        $ids = $this->scenario();
        $this->employee->forceFill(['locale' => 'id'])->save();

        $pages = [
            [$this->admin, route('users.index'), 'en'],
            [$this->admin, route('users.show', $this->employee->id), 'en'],
            [$this->admin, route('recap.index'), 'en'],
            [$this->approver, route('approvals.show', ['type' => 'overtime', 'id' => $ids['overtime']]), 'en'],
            [$this->employee, route('requests.index'), 'id'],
            [$this->employee, route('profile.show'), 'id'],
        ];

        foreach ($pages as [$user, $url, $lang]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $dom = $this->dom($html);
            $this->assertSame($lang, $dom->documentElement->getAttribute('lang'), "$url: lang");

            $idList = array_map(fn ($el) => $el->getAttribute('id'), iterator_to_array($dom->querySelectorAll('[id]')));
            $this->assertSame([], array_values(array_unique(array_diff_assoc($idList, array_unique($idList)))), "$url: duplicate ids");

            $this->assertCount(1, $dom->querySelectorAll('h1'), "$url: one h1");
            $this->assertCount(1, $dom->querySelectorAll('main'), "$url: one main");
            $this->assertSame('#main', $dom->querySelector('body a[href]')?->getAttribute('href'), "$url: the skip link comes first");

            foreach ($dom->querySelectorAll('img') as $img) {
                $this->assertTrue($img->hasAttribute('alt'), "$url: img alt");
            }

            foreach ($dom->querySelectorAll('input:not([type=hidden]), select, textarea') as $field) {
                $id = (string) $field->getAttribute('id');
                $named = (string) $field->getAttribute('aria-label') !== ''
                    || (string) $field->getAttribute('aria-labelledby') !== ''
                    || ($id !== '' && $dom->querySelector('label[for="'.$id.'"]') !== null)
                    || $field->closest('label') !== null;
                $this->assertTrue($named, "$url: field without a label: ".$dom->saveHtml($field));
            }

            foreach ($dom->querySelectorAll('button:not([aria-hidden=true]), a[href]:not([aria-hidden=true])') as $control) {
                $name = self::clean($control->textContent.$control->getAttribute('aria-label').$control->getAttribute('title'));
                $this->assertNotSame('', $name, "$url: control without a name: ".$dom->saveHtml($control));
            }

            foreach ($dom->querySelectorAll('[aria-labelledby], [aria-describedby], [aria-controls]') as $el) {
                foreach (['aria-labelledby', 'aria-describedby', 'aria-controls'] as $attribute) {
                    foreach (array_filter(explode(' ', (string) $el->getAttribute($attribute))) as $ref) {
                        $this->assertNotNull($dom->getElementById($ref), "$url: $attribute=$ref");
                    }
                }
            }
        }
    }
}
