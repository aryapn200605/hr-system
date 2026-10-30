<?php

namespace Tests\Feature;

use App\Enums\LoginResult;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Dashboard;
use App\Models\LoginHistory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class AppLayoutTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Load the dashboard the way a new request would.
     */
    private function dashboardAs(User $user): TestResponse
    {
        $this->startNextRequest();

        return $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    }

    /**
     * Assert the tone of the role badge itself, since other badges on the page can share it.
     */
    private function assertRoleBadge(TestResponse $response, string $tone): void
    {
        $this->assertMatchesRegularExpression('/<span data-tone="'.$tone.'"[^>]*data-role-badge/', $response->getContent());
    }

    /**
     * Create a user with a role.
     */
    private function userWithRole(RoleName $role): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create(['status' => UserStatus::Active]);
    }

    /**
     * @return array<string, array{RoleName, string}>
     */
    public static function roleColors(): array
    {
        return [
            'HR Admin is blue' => [RoleName::HrAdmin, 'blue'],
            'Approver is purple' => [RoleName::Approver, 'purple'],
            'Employee is green' => [RoleName::Employee, 'green'],
        ];
    }

    #[DataProvider('roleColors')]
    public function test_the_sidebar_shows_name_email_and_role_badge(RoleName $role, string $tone): void
    {
        $user = $this->userWithRole($role);

        $response = $this->dashboardAs($user)
            ->assertSee($user->name)
            ->assertSee($user->email)
            ->assertSee($role->value);

        $this->assertRoleBadge($response, $tone);
    }

    public function test_the_role_badge_follows_a_role_change(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);
        $user = $this->userWithRole(RoleName::Employee);

        $before = $this->dashboardAs($user)
            ->assertSee(RoleName::Employee->value)
            ->assertDontSee(RoleName::Approver->value);

        $this->assertRoleBadge($before, 'green');

        $user->changeRole(RoleName::Approver, $admin);

        $after = $this->dashboardAs($user)
            ->assertSee(RoleName::Approver->value)
            ->assertDontSee(RoleName::Employee->value);

        $this->assertRoleBadge($after, 'purple');
    }

    public function test_the_role_badge_reads_the_name_from_the_database(): void
    {
        $user = $this->userWithRole(RoleName::Employee);

        $user->roles()->first()->update(['name' => 'Staff']);

        $response = $this->dashboardAs($user)
            ->assertSee('Staff')
            ->assertDontSee(RoleName::Employee->value);

        $this->assertRoleBadge($response, 'gray');
    }

    public function test_a_user_without_a_role_is_told_so(): void
    {
        $user = User::factory()->twoFactorEnrolled()->create(['status' => UserStatus::Active]);
        $user->givePermissionTo(PermissionName::DashboardView);

        $this->dashboardAs($user)->assertSee('No role');
    }

    #[DataProvider('rolesWithoutUserAccess')]
    public function test_user_management_is_hidden_without_the_permission(RoleName $role): void
    {
        $this->dashboardAs($this->userWithRole($role))
            ->assertSee('Home')
            ->assertDontSee('User Management');
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutUserAccess(): array
    {
        return [
            'Approver' => [RoleName::Approver],
            'Employee' => [RoleName::Employee],
        ];
    }

    public function test_user_management_links_to_the_user_list_for_hr_admin(): void
    {
        $html = $this->dashboardAs($this->userWithRole(RoleName::HrAdmin))
            ->assertSee('Home')
            ->assertSee('User Management')
            ->getContent();

        $this->assertMatchesRegularExpression('/<a[^>]*href="'.preg_quote(route('users.index'), '/').'"[^>]*>(?:(?!<\/a>).)*User Management/s', $html);
    }

    public function test_the_menu_follows_the_permission_and_not_the_role_name(): void
    {
        $user = $this->userWithRole(RoleName::Employee);
        $user->givePermissionTo(PermissionName::UsersView);

        $this->dashboardAs($user)->assertSee('User Management');
    }

    public function test_the_menu_disappears_when_the_role_loses_the_permission(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);
        $target = $this->userWithRole(RoleName::HrAdmin);

        $this->dashboardAs($target)->assertSee('User Management');

        $target->changeRole(RoleName::Employee, $admin);

        $this->dashboardAs($target)->assertDontSee('User Management');
    }

    public function test_the_profile_menu_offers_profile_security_and_logout(): void
    {
        $this->dashboardAs($this->userWithRole(RoleName::Employee))
            ->assertSee('My Profile')
            ->assertSee('Security Settings')
            ->assertSee('Logout')
            ->assertSee(route('logout'))
            ->assertSee('aria-current="page"', false);
    }

    public function test_the_dashboard_shows_the_account_and_security_state(): void
    {
        $user = $this->userWithRole(RoleName::Employee);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->dashboardAs($user)
            ->assertSee('Welcome, '.$user->name)
            ->assertSee($user->username)
            ->assertSee('Security status')
            ->assertSee('Enabled')
            ->assertSee('Account information')
            ->assertSee('Active');
    }

    public function test_a_new_account_gets_empty_states_and_no_invented_numbers(): void
    {
        $user = $this->userWithRole(RoleName::Employee);

        $response = $this->dashboardAs($user)
            ->assertSee('No login activity yet')
            ->assertSee('Not recorded yet');

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('successfulLogins', 0)
            ->assertViewHas('failedLogins', 0)
            ->assertViewHas('recoveryCodesLeft', 0);

        $this->assertStringNotContainsString('<table', $response->getContent());
    }

    public function test_the_summary_counts_only_this_user_and_only_recent_logins(): void
    {
        $user = $this->userWithRole(RoleName::Employee);
        $other = $this->userWithRole(RoleName::Employee);

        foreach ([
            [$user, LoginResult::Success, now()],
            [$user, LoginResult::Success, now()->subDays(29)],
            [$user, LoginResult::FailedTotp, now()],
            [$user, LoginResult::Success, now()->subDays(31)],
            [$other, LoginResult::FailedCredentials, now()],
        ] as [$owner, $result, $occurredAt]) {
            LoginHistory::create([
                'user_id' => $owner->id,
                'result' => $result->value,
                'ip_address' => '203.0.113.9',
                'occurred_at' => $occurredAt,
            ]);
        }

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertViewHas('successfulLogins', 2)
            ->assertViewHas('failedLogins', 1)
            ->assertViewHas('recentLogins', fn ($logins) => $logins->count() === 4)
            ->assertSee('Wrong authenticator code')
            ->assertDontSee('Wrong password');
    }

    public function test_only_users_with_the_permission_get_account_counts(): void
    {
        $admin = $this->userWithRole(RoleName::HrAdmin);
        $employee = $this->userWithRole(RoleName::Employee);
        User::factory()->create(['status' => UserStatus::Suspended]);

        Livewire::actingAs($admin)->test(Dashboard::class)
            ->assertViewHas('accountCounts', [UserStatus::Active->value => 2, UserStatus::Suspended->value => 1]);

        Livewire::actingAs($employee)->test(Dashboard::class)
            ->assertViewHas('accountCounts', null)
            ->assertDontSee('User accounts');
    }

    public function test_livewire_refuses_a_user_without_the_permission(): void
    {
        $user = User::factory()->twoFactorEnrolled()->create(['status' => UserStatus::Active]);

        Livewire::actingAs($user)->test(Dashboard::class)->assertForbidden();
    }

    public function test_refresh_reloads_the_whole_page_and_leaves_a_toast(): void
    {
        Livewire::actingAs($this->userWithRole(RoleName::Employee))->test(Dashboard::class)
            ->call('refresh')
            ->assertRedirect(route('dashboard'));

        $this->assertSame('Dashboard refreshed.', session('toast'));
    }

    public function test_a_toast_message_cannot_inject_markup(): void
    {
        $this->withSession(['toast' => '<img src=x onerror=alert(1)>']);

        $this->dashboardAs($this->userWithRole(RoleName::Employee))
            ->assertDontSee('<img src=x', false)
            ->assertSee('x-text="toast.message"', false)
            ->assertDontSee('x-html', false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicPages(): array
    {
        return [
            'login' => ['login'],
            'forgot password' => ['password.request'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_public_pages_load_no_livewire_and_no_app_shell(string $route): void
    {
        $this->get(route($route))
            ->assertOk()
            ->assertDontSee('wire:snapshot', false)
            ->assertDontSee('x-data', false)
            ->assertDontSee('Skip to content');
    }

    public function test_an_input_links_its_label_and_error_message(): void
    {
        View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => 'Bad address.'])));

        $html = Blade::render('<x-ui.input label="Email" name="email" hint="Work address" />');

        $this->assertStringContainsString('for="email"', $html);
        $this->assertStringContainsString('id="email"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="email-hint email-error"', $html);
        $this->assertStringContainsString('id="email-error"', $html);
        $this->assertStringContainsString('Bad address.', $html);
    }

    public function test_an_input_never_refills_its_value_by_itself(): void
    {
        View::share('errors', new ViewErrorBag);

        $this->withSession(['_old_input' => ['identifier' => 'secret-typed-here']]);

        $html = Blade::render('<x-ui.input label="Username" name="identifier" />');

        $this->assertStringNotContainsString('secret-typed-here', $html);
    }

    public function test_a_modal_is_labelled_by_its_title(): void
    {
        $html = Blade::render('<x-ui.modal name="confirm" title="Are you sure?">Body</x-ui.modal>');

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-labelledby="confirm-title"', $html);
        $this->assertStringContainsString('id="confirm-title"', $html);
    }

    public function test_a_table_and_empty_state_render_their_content(): void
    {
        $table = Blade::render('<x-ui.table caption="People"><x-slot:head><tr><th>Name</th></tr></x-slot:head><tr><td>Rina</td></tr></x-ui.table>');
        $empty = Blade::render('<x-ui.empty-state title="Nothing here" description="Add something." />');

        $this->assertStringContainsString('<caption class="sr-only">People</caption>', $table);
        $this->assertStringContainsString('<th>Name</th>', $table);
        $this->assertStringContainsString('Nothing here', $empty);
        $this->assertStringContainsString('Add something.', $empty);
    }
}
