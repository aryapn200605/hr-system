<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Users\Index as UsersIndex;
use App\Mail\PasswordResetMail;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Requests\Decisions;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Supervisors;
use App\Support\MailDelivery;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 15d: the company name, the shared pieces of the application pages (page heading, section cards, figure
 * cards, toolbar, row menus, pagination, skeleton, empty states) and the pages that use them.
 */
class ApplicationPagesDesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(RoleName $role, array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create(['status' => UserStatus::Active] + $attributes);
    }

    // ---- Company name -------------------------------------------------------------------------------------

    public function test_the_company_name_is_the_one_decided_by_the_owner(): void
    {
        $this->assertSame('Peranti Digital Solusindo', config('app.company'));
        $this->assertSame('PT Peranti Digital Solusindo', config('app.company_legal'));
    }

    public function test_titles_sidebar_and_auth_footer_show_the_company(): void
    {
        $html = $this->actingAs($this->userWithRole(RoleName::Employee))->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>Home - '.config('app.name').' - Peranti Digital Solusindo</title>', $html);
        $this->assertMatchesRegularExpression('#<span class="truncate font-semibold">Peranti Digital Solusindo</span>#', $html);

        auth()->logout();
        $login = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString(' - Peranti Digital Solusindo</title>', $login);
        $this->assertStringContainsString('&copy; '.now()->year.' PT Peranti Digital Solusindo &middot; '.config('app.name'), $login);
    }

    public function test_emails_carry_the_company_in_the_header_and_the_legal_name_in_the_footer(): void
    {
        MailDelivery::send('rina@example.com', new PasswordResetMail('Rina', str_repeat('a', 64)), 'en');
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        foreach ([$message->getHtmlBody(), $message->getTextBody()] as $body) {
            $this->assertStringContainsString('Peranti Digital Solusindo '.config('app.name'), $body);
            $this->assertStringContainsString('© '.date('Y').' PT Peranti Digital Solusindo.', $body);
        }
    }

    public function test_the_logo_lettering_is_not_used_as_a_name(): void
    {
        foreach (['resources/views', 'config', 'app'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $this->assertStringNotContainsString('Solusi Peranti Digital', file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }

    // ---- Dialog entrance and fixed panels ------------------------------------------------------------------

    public function test_the_entrance_animation_does_not_stay_in_effect_after_it_ends(): void
    {
        // A finished animation that fills forwards keeps its transform in effect, and the dialog then becomes the
        // containing block of the select and date picker panels (position: fixed): they were shifted off screen.
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/@utility animate-auth-enter \{\s*animation: auth-enter [^;]*\bbackwards;\s*\}/', $css);
        $this->assertDoesNotMatchRegularExpression('/animation:[^;]*\b(both|forwards)\b/', $css);
    }

    // ---- Shared pieces -------------------------------------------------------------------------------------

    public function test_the_page_heading_has_an_icon_a_back_link_and_a_quiet_backdrop(): void
    {
        $html = Blade::render('<x-ui.page title="Ana" icon="user" :back="$back">Body</x-ui.page>', ['back' => ['href' => '/users', 'label' => 'Back to users']]);

        $this->assertMatchesRegularExpression('#<a href="/users"[^>]*data-page-back>\s*<svg[^>]*>.*?</svg>\s*Back to users\s*</a>#s', $html);
        $this->assertMatchesRegularExpression('#data-icon-chip="primary"#', $html);
        $this->assertMatchesRegularExpression('#class="page-backdrop[^"]*"\s+aria-hidden="true"\s+data-page-backdrop#', $html);
        // The backdrop is clipped to the container, which is positioned, so it cannot widen the page; the other
        // children are positioned so they paint over it. No stacking context: dialogs inside the page must still
        // cover the sidebar and the header (with "isolate" they were drawn under them).
        $this->assertMatchesRegularExpression('#data-page\s+data-width="default"\s+class="relative mx-auto[^"]*\[&amp;&gt;:not\(\[data-page-backdrop\],\.fixed\)\]:relative#', $html);
        $this->assertDoesNotMatchRegularExpression('#data-page\s+data-width="default"\s+class="[^"]*(isolate|z-\d+)#', $html);
        // Dialogs (fixed) keep their own position.
        $this->assertDoesNotMatchRegularExpression('#<div class="[^"]*-z-\d+[^"]*"[^>]*data-page-backdrop#', $html);

        $plain = Blade::render('<x-ui.page title="Ana">Body</x-ui.page>');
        $this->assertStringNotContainsString('data-page-back>', $plain);
        $this->assertStringNotContainsString('data-icon-chip', $plain);
    }

    public function test_section_cards_have_an_icon_actions_and_a_footer(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.card title="Employment" description="Where they work." icon="briefcase" tone="teal">
                <x-slot:actions><button type="button">Help</button></x-slot:actions>
                <p>Body</p>
                <x-slot:footer><button type="button">Edit employment</button></x-slot:footer>
            </x-ui.card>
            BLADE);

        $this->assertStringContainsString('data-icon-chip="teal"', $html);
        $this->assertMatchesRegularExpression('#data-card-actions><button type="button">Help</button>#', $html);
        $this->assertMatchesRegularExpression('#<footer class="mt-auto[^"]*" data-card-footer><button type="button">Edit employment</button></footer>#', $html);
        $this->assertLessThan(strpos($html, 'data-card-footer'), strpos($html, '<p>Body</p>'));

        $bare = Blade::render('<x-ui.card title="Plain"><p>Body</p></x-ui.card>');
        $this->assertStringNotContainsString('data-card-footer', $bare);
        $this->assertStringNotContainsString('data-card-actions', $bare);
    }

    public function test_figure_cards_badges_and_empty_states(): void
    {
        $stat = Blade::render('<x-ui.stat label="Remaining" value="8 days" icon="wallet" tone="green" />');
        $this->assertStringContainsString('data-icon-chip="green"', $stat);
        $this->assertMatchesRegularExpression('#data-value>8 days</p>#', $stat);

        $this->assertStringContainsString('data-badge-dot', Blade::render('<x-ui.badge tone="green" dot>Active</x-ui.badge>'));
        $this->assertStringNotContainsString('data-badge-dot', Blade::render('<x-ui.badge tone="green">Active</x-ui.badge>'));
        $this->assertStringContainsString('data-badge-dot', Blade::render('<x-ui.status-badge :status="$s" dot />', ['s' => UserStatus::Active]));

        $empty = Blade::render('<x-ui.empty-state title="No holidays" description="Add one." icon="flag"><button type="button">Add holiday</button></x-ui.empty-state>');
        $this->assertMatchesRegularExpression('#<svg class="absolute inset-0[^"]*"[^>]*>#', $empty);
        $this->assertMatchesRegularExpression('#aria-hidden="true">\s*<svg#', $empty);
        $this->assertStringContainsString('<button type="button">Add holiday</button>', $empty);
        $this->assertDoesNotMatchRegularExpression('#<img|https?://#', $empty);
    }

    public function test_row_menus_are_icon_buttons_anchored_with_the_fixed_strategy(): void
    {
        $html = Blade::render('<x-ui.row-actions id="row-1" label="Actions for Ana"><x-ui.dropdown-item>Edit</x-ui.dropdown-item></x-ui.row-actions>');

        $this->assertStringContainsString('aria-label="Actions for Ana"', $html);
        $this->assertStringContainsString('x-anchor.fixed.bottom-end.offset.4="$refs.trigger"', $html);
        $this->assertStringContainsString('aria-controls="row-1"', $html);
    }

    public function test_pagination_footer(): void
    {
        $page = new LengthAwarePaginator(range(1, 10), 35, 10, 2);
        $html = Blade::render('<x-ui.pagination :paginator="$p" />', ['p' => $page]);

        $this->assertStringContainsString('Showing 11 to 20 of 35', $html);
        $this->assertStringContainsString('Page 2 of 4', $html);
        foreach (['Go to first page', 'Previous', 'Next', 'Go to last page'] as $name) {
            $this->assertMatchesRegularExpression('#<span class="sr-only">'.$name.'</span>#', $html);
        }
        $this->assertStringContainsString('wire:click="gotoPage(4)"', $html);
        $this->assertStringContainsString('wire:click="gotoPage(1)"', $html);

        // First page: first and previous disabled; last page: next and last disabled.
        $first = Blade::render('<x-ui.pagination :paginator="$p" />', ['p' => new LengthAwarePaginator(range(1, 10), 35, 10, 1)]);
        $this->assertMatchesRegularExpression('#wire:click="previousPage" wire:loading.attr="disabled" disabled#', $first);
        $this->assertDoesNotMatchRegularExpression('#wire:click="nextPage" wire:loading.attr="disabled" disabled#', $first);
        $last = Blade::render('<x-ui.pagination :paginator="$p" />', ['p' => new LengthAwarePaginator(range(1, 5), 35, 10, 4)]);
        $this->assertMatchesRegularExpression('#wire:click="nextPage" wire:loading.attr="disabled" disabled#', $last);

        // No row, no footer.
        $this->assertStringNotContainsString('data-pagination', Blade::render('<x-ui.pagination :paginator="$p" />', ['p' => new LengthAwarePaginator([], 0, 10, 1)]));
    }

    public function test_the_skeleton_waits_for_its_target_and_stays_hidden_otherwise(): void
    {
        $html = Blade::render('<x-ui.table-skeleton target="search,nextPage" :columns="3" :rows="2" />');

        $this->assertMatchesRegularExpression('#wire:loading.delay.block wire:target="search,nextPage"\s+class="hidden"\s+role="status"#', $html);
        $this->assertStringContainsString('motion-safe:animate-pulse', $html);
        $this->assertStringContainsString('<span class="sr-only">Loading…</span>', $html);
    }

    // ---- Group 1: users and departments --------------------------------------------------------------------

    public function test_the_users_page_uses_the_toolbar_row_menus_and_pagination(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);
        $other = $this->userWithRole(RoleName::Employee, ['name' => 'Rina Employee']);

        $html = $this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-toolbar', $html);
        $this->assertStringContainsString('data-pagination', $html);
        $this->assertStringContainsString('data-skeleton', $html);
        $this->assertStringContainsString('aria-controls="user-actions-'.$other->id.'"', $html);
        $this->assertStringContainsString('aria-label="Actions for Rina Employee"', $html);
        // The rows-per-page select moved into the footer, after the range.
        $this->assertLessThan(strpos($html, 'id="filter-per-page"'), strpos($html, 'data-pagination-range'));
        $this->assertStringNotContainsString('data-clear-filters', $html);

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->set('search', 'Rina')
            ->assertSeeHtml('data-clear-filters')
            ->set('search', '')
            ->assertDontSeeHtml('data-clear-filters');
    }

    public function test_the_departments_page_shows_figures_from_its_list(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);
        Department::create(['name' => 'Finance', 'is_active' => true]);
        Department::create(['name' => 'Archive', 'is_active' => false]);

        $html = $this->actingAs($admin)->get(route('departments.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-department-stats>.*?Departments.*?data-value>2</p>.*?Active.*?data-value>1</p>.*?Employees.*?data-value>0</p>#s', $html);
        $this->assertStringContainsString('department-actions-', $html);
    }

    // ---- Group 2: supervisors, leave balance, holidays -----------------------------------------------------

    public function test_the_leave_balance_page_shows_figure_cards_and_card_footers(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);
        $employee = $this->userWithRole(RoleName::Employee);

        $html = $this->actingAs($admin)->get(route('users.leave-balance', $employee->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-balance-stats>.*?Entitlement.*?Used.*?Remaining#s', $html);
        $this->assertMatchesRegularExpression('#data-page-back>.*?Back to user\s*</a>#s', $html);
        // The adjust, reset and anchor buttons sit in the cards' footers, only for someone who may adjust.
        $this->assertMatchesRegularExpression('#data-card-footer>.*?wire:click="openAdjust".*?wire:click="openReset"#s', $html);
        $this->assertMatchesRegularExpression('#data-card-footer>.*?wire:click="openAnchor"#s', $html);

        // Their own balance: the notice, no footer buttons.
        $own = $this->actingAs($admin)->get(route('users.leave-balance', $admin->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-self-notice', $own);
        $this->assertStringNotContainsString('wire:click="openAdjust"', $own);
    }

    public function test_the_holidays_page_uses_a_toolbar_row_menus_and_an_empty_state_with_an_action(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);

        $empty = $this->actingAs($admin)->get(route('holidays.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-toolbar', $empty);
        $this->assertMatchesRegularExpression('#data-empty-state>.*?wire:click="openCreate"#s', $empty);

        $holiday = Holiday::create(['date' => now()->year.'-12-25', 'name' => 'Christmas Day']);
        $html = $this->actingAs($admin)->get(route('holidays.index'))->assertOk()->getContent();
        $this->assertStringContainsString('aria-controls="holiday-actions-'.$holiday->id.'"', $html);
        $this->assertStringContainsString('aria-label="Actions for Christmas Day"', $html);
        $this->assertMatchesRegularExpression('#wire:click="openDelete\('.$holiday->id.'\)"[^>]*>.*?Delete#s', $html);
    }

    public function test_supervisor_and_subordinate_cards_keep_their_actions_in_footers(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);
        $approver = $this->userWithRole(RoleName::Approver);
        $employee = $this->userWithRole(RoleName::Employee);
        app(Supervisors::class)->attach($admin, $employee, (string) $approver->id);

        $html = $this->actingAs($admin)->get(route('users.show', $approver->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-card-footer>.*?wire:click="openMove"#s', $html);
        $this->assertMatchesRegularExpression('#data-card-footer>.*?wire:click="openAddSupervisor"#s', $html);

        $view = $this->actingAs($employee)->get(route('users.show', $approver->id));
        $view->assertForbidden();
    }

    // ---- Group 3: requests and approvals --------------------------------------------------------------------

    public function test_the_segmented_control_sets_its_property_and_marks_the_chosen_option(): void
    {
        $html = Blade::render('<x-ui.segmented label="List" :options="[\'waiting\' => \'Waiting\', \'decided\' => \'Decided\']" value="decided" property="tab" data-attribute="tab" />');

        $this->assertMatchesRegularExpression('#role="group" aria-labelledby="segmented-tab-label"#', $html);
        $this->assertMatchesRegularExpression('#wire:click="\$set\(\'tab\', \'waiting\'\)"\s+aria-pressed="false"\s+data-tab="waiting"#', $html);
        $this->assertMatchesRegularExpression('#wire:click="\$set\(\'tab\', \'decided\'\)"\s+aria-pressed="true"\s+data-tab="decided"#', $html);
    }

    public function test_my_requests_shows_balance_tiles_and_an_empty_state_with_the_new_request_action(): void
    {
        $employee = $this->userWithRole(RoleName::Employee);
        $supervisor = $this->userWithRole(RoleName::Approver);
        app(Supervisors::class)->attach($this->userWithRole(RoleName::HrAdmin), $employee, (string) $supervisor->id);

        $html = $this->actingAs($employee)->get(route('requests.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-slot="0">.*?Entitlement.*?Used.*?In pending requests.*?<span\s+data-available\s*>#s', $html);
        $this->assertStringContainsString('data-segmented="type"', $html);
        $this->assertMatchesRegularExpression('#data-empty-state>.*?wire:click="openLeave"#s', $html);

        // Without an approval path the empty state offers nothing (the header buttons are disabled instead).
        $alone = $this->userWithRole(RoleName::Employee);
        $html = $this->actingAs($alone)->get(route('requests.index'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('#data-empty-state>(?:(?!</div>\s*</div>).)*wire:click="openLeave"#s', $html);
    }

    public function test_a_request_shows_its_progress(): void
    {
        $employee = $this->userWithRole(RoleName::Employee);
        $supervisor = $this->userWithRole(RoleName::Approver);
        app(Supervisors::class)->attach($this->userWithRole(RoleName::HrAdmin), $employee, (string) $supervisor->id);
        $monday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY);
        $request = app(OvertimeRequests::class)->submit($employee, ['start_at' => $monday->toDateString().' 18:00', 'end_at' => $monday->toDateString().' 20:00', 'reason' => 'Release night support.']);

        $pending = $this->actingAs($employee)->get(route('requests.show', ['type' => 'overtime', 'id' => $request->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-step="0">.*?Submitted.*?data-step="1">.*?Pending approval#s', $pending);
        $this->assertMatchesRegularExpression('#data-page-back>.*?Back to my requests#s', $pending);

        app(Decisions::class)->reject($supervisor, 'overtime', $request->id, 'Not this week.');
        $rejected = $this->actingAs($employee)->get(route('requests.show', ['type' => 'overtime', 'id' => $request->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-step="1">.*?Rejected.*?'.preg_quote($supervisor->name, '#').'#s', $rejected);
        $this->assertMatchesRegularExpression('#data-icon-chip="red"#', $rejected);
    }

    public function test_the_decision_page_keeps_its_markers_and_the_final_warning(): void
    {
        $employee = $this->userWithRole(RoleName::Employee);
        $supervisor = $this->userWithRole(RoleName::Approver);
        app(Supervisors::class)->attach($this->userWithRole(RoleName::HrAdmin), $employee, (string) $supervisor->id);
        $monday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY);
        $request = app(LeaveRequests::class)->submit($employee, ['category' => 'sick', 'starts_on' => $monday->toDateString(), 'ends_on' => $monday->toDateString(), 'reason' => 'Doctor appointment in town.']);

        $html = $this->actingAs($supervisor)->get(route('approvals.show', ['type' => 'leave', 'id' => $request->id]))->assertOk()->getContent();
        foreach (['data-close', 'data-approve', 'data-reject', 'data-confirm-approve', 'data-confirm-reject', 'data-slot="0"', 'data-remaining', 'data-used'] as $marker) {
            $this->assertStringContainsString($marker, $html);
        }
        $this->assertSame(2, substr_count($html, 'data-final-warning'));
        $this->assertMatchesRegularExpression('#data-final-warning>\s*<svg[^>]*>.*?</svg>\s*<span>This decision is final\. It cannot be undone or changed later\.</span>#s', $html);
    }

    // ---- Group 4: history, recap, dashboard ----------------------------------------------------------------

    public function test_history_and_recap_use_the_toolbar_skeleton_and_pagination(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);

        $history = $this->actingAs($admin)->get(route('history.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-toolbar', $history);
        $this->assertStringContainsString('data-history-total', $history);
        $this->assertStringContainsString('wire:target="employee,department,type,category,status,from,to,clearFilters,gotoPage,nextPage,previousPage"', $history);
        $this->assertStringContainsString('data-export', $history);

        $recap = $this->actingAs($admin)->get(route('recap.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-segmented="tab"', $recap);
        $this->assertMatchesRegularExpression('#aria-pressed="true"\s+data-tab="balance"#', $recap);
        $this->assertStringContainsString('data-toolbar', $recap);
    }

    public function test_the_dashboard_figure_cards_carry_tinted_icons(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        foreach (['green', 'amber'] as $tone) {
            $this->assertStringContainsString('data-icon-chip="'.$tone.'"', $html);
        }
        $this->assertMatchesRegularExpression('#data-pending-total[^>]*>.*?data-icon-chip="amber"#s', $html);
    }

    public function test_an_empty_departments_page_offers_the_first_department(): void
    {
        $html = $this->actingAs($this->userWithRole(RoleName::HrAdmin))->get(route('departments.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-department-stats', $html);
        $this->assertMatchesRegularExpression('#data-empty-state>.*?No departments yet.*?wire:click="openCreate"#s', $html);
    }
}
