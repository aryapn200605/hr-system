<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Users\Index;
use App\Livewire\Users\Show;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class UserManagementAccessTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $this->admin = $this->hrAdmin();
    }

    private function userWithRole(RoleName $role): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create();
    }

    /**
     * Take the snapshot an HR Admin's browser holds, for replaying it as someone else.
     */
    private function adminSnapshot(): string
    {
        $html = $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->getContent();

        preg_match('/wire:snapshot="([^"]+)"[^>]*wire:effects/', $html, $matches);

        return html_entity_decode($matches[1]);
    }

    /**
     * Assert that an action is refused, on a component of its own: a refused one has no state left.
     */
    private function assertRefused(User $actor, string $method, mixed ...$params): void
    {
        Livewire::actingAs($actor)->test(Index::class)->call($method, ...$params)->assertForbidden();
    }

    /**
     * Send a request to the real update endpoint.
     *
     * @param  array<string, mixed>  $updates
     * @param  array<int, mixed>  $params
     */
    private function callLivewire(string $snapshot, ?string $method, array $params = [], array $updates = []): TestResponse
    {
        return $this->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates ?: new stdClass,
                'calls' => $method ? [['method' => $method, 'params' => $params, 'metadata' => new stdClass]] : [],
            ]],
        ], ['X-Livewire' => '1']);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return [
            'Approver' => [RoleName::Approver],
            'Employee' => [RoleName::Employee],
        ];
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function actions(): array
    {
        return [
            'openCreate' => ['openCreate', false],
            'saveUser' => ['saveUser', false],
            'openEdit' => ['openEdit', true],
            'openRole' => ['openRole', true],
            'saveRole' => ['saveRole', false],
            'openSuspend' => ['openSuspend', true],
            'openReactivate' => ['openReactivate', true],
            'openResend' => ['openResend', true],
            'confirm' => ['confirm', false],
        ];
    }

    public function test_both_routes_carry_the_permission_middleware(): void
    {
        foreach (['users.index', 'users.show'] as $name) {
            $this->assertContains('can:'.PermissionName::UsersView->value, Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
        $this->get(route('users.show', $this->admin->id))->assertRedirect(route('login'));
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_direct_urls_are_refused(RoleName $role): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->get(route('users.show', $this->admin->id))->assertForbidden();
    }

    public function test_the_refusal_does_not_reveal_which_ids_exist(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Employee));

        $this->get(route('users.show', $this->admin->id))->assertForbidden();
        $this->get(route('users.show', 999999))->assertForbidden();
    }

    public function test_an_admin_gets_404_for_an_unknown_or_malformed_id(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('users.show', 999999))->assertNotFound();
        $this->get('/users/abc')->assertNotFound();
        $this->get('/users/99999999999999999999999')->assertNotFound();
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_the_components_refuse_to_mount(RoleName $role): void
    {
        $user = $this->userWithRole($role);

        Livewire::actingAs($user)->test(Index::class)->assertForbidden();
        Livewire::actingAs($user)->test(Show::class, ['user' => (string) $this->admin->id])->assertForbidden();
    }

    /**
     * The payload is valid and signed: it came from an admin's page. Only the user behind it differs.
     */
    #[DataProvider('actions')]
    public function test_a_replayed_action_is_refused_for_employee_and_approver(string $method, bool $takesId): void
    {
        $target = $this->userWithRole(RoleName::Employee);
        $snapshot = $this->adminSnapshot();
        $params = $takesId ? [$target->id] : [];

        foreach ([RoleName::Employee, RoleName::Approver] as $role) {
            $this->startNextRequest();
            $this->actingAs($this->userWithRole($role));

            $this->callLivewire($snapshot, $method, $params)->assertForbidden();
        }

        $this->assertSame(UserStatus::Active, $target->fresh()->status);
    }

    public function test_a_replayed_filter_update_is_refused(): void
    {
        $snapshot = $this->adminSnapshot();

        $this->startNextRequest();
        $this->actingAs($this->userWithRole(RoleName::Employee));

        $this->callLivewire($snapshot, null, updates: ['search' => 'a'])->assertForbidden();
    }

    public function test_a_replayed_action_is_refused_after_the_admin_lost_the_role(): void
    {
        $other = $this->hrAdmin();
        $target = $this->userWithRole(RoleName::Employee);
        $snapshot = $this->adminSnapshot();

        $this->admin->changeRole(RoleName::Employee, $other);

        $this->startNextRequest();
        $this->actingAs($this->admin->fresh());

        $this->callLivewire($snapshot, 'openSuspend', [$target->id])->assertForbidden();
    }

    public function test_viewing_alone_allows_no_action(): void
    {
        $viewer = $this->userWithRole(RoleName::Employee);
        $viewer->givePermissionTo(PermissionName::UsersView);
        $target = $this->userWithRole(RoleName::Employee);
        $pending = User::factory()->pending()->withRole(RoleName::Employee)->create();
        $suspended = User::factory()->suspended()->withRole(RoleName::Employee)->create();

        Livewire::actingAs($viewer)->test(Index::class)
            ->assertOk()
            ->assertSee($target->name)
            ->assertDontSee('wire:click="openCreate', false)
            ->assertDontSee('wire:click="openRole', false)
            ->assertDontSee('wire:click="openSuspend', false);

        $this->assertRefused($viewer, 'openCreate');
        $this->assertRefused($viewer, 'saveUser');
        $this->assertRefused($viewer, 'openEdit', $target->id);
        $this->assertRefused($viewer, 'openRole', $target->id);
        $this->assertRefused($viewer, 'openSuspend', $target->id);
        $this->assertRefused($viewer, 'openResend', $pending->id);
        $this->assertRefused($viewer, 'openReactivate', $suspended->id);
    }

    /**
     * @return array<string, array{string, PermissionName}>
     */
    public static function actionsAndTheirPermission(): array
    {
        return [
            'edit needs users.update' => ['openEdit', PermissionName::UsersUpdate],
            'role needs users.update' => ['openRole', PermissionName::UsersUpdate],
            'suspend needs users.suspend' => ['openSuspend', PermissionName::UsersSuspend],
        ];
    }

    #[DataProvider('actionsAndTheirPermission')]
    public function test_each_action_needs_its_own_permission(string $method, PermissionName $permission): void
    {
        $target = $this->userWithRole(RoleName::Employee);

        $others = array_filter(PermissionName::cases(), fn (PermissionName $case) => $case !== $permission);

        $user = $this->userWithRole(RoleName::Employee);
        $user->givePermissionTo($others);

        Livewire::actingAs($user)->test(Index::class)->call($method, $target->id)->assertForbidden();

        $user->givePermissionTo($permission);

        Livewire::actingAs($user->fresh())->test(Index::class)->call($method, $target->id)->assertOk();
    }

    public function test_each_save_rechecks_after_the_dialog_was_opened(): void
    {
        $target = $this->userWithRole(RoleName::Employee);
        $manager = $this->userWithRole(RoleName::Employee);
        $manager->givePermissionTo([PermissionName::UsersView, PermissionName::UsersUpdate, PermissionName::UsersSuspend]);

        $edit = Livewire::actingAs($manager)->test(Index::class)->call('openEdit', $target->id);
        $role = Livewire::actingAs($manager)->test(Index::class)->call('openRole', $target->id)->set('role', RoleName::Approver->value);
        $suspend = Livewire::actingAs($manager)->test(Index::class)->call('openSuspend', $target->id);

        $manager->revokePermissionTo([PermissionName::UsersUpdate, PermissionName::UsersSuspend]);
        $manager->unsetRelation('permissions');

        $edit->set('name', 'Changed')->call('saveUser')->assertForbidden();
        $role->call('saveRole')->assertForbidden();
        $suspend->call('confirm')->assertForbidden();

        $this->assertNotSame('Changed', $target->fresh()->name);
        $this->assertSame(RoleName::Employee->value, $target->fresh()->roleName());
        $this->assertSame(UserStatus::Active, $target->fresh()->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lockedProperties(): array
    {
        return [
            'targetId' => ['targetId'],
            'targetName' => ['targetName'],
            'confirming' => ['confirming'],
            'identityLocked' => ['identityLocked'],
            'notice' => ['notice'],
        ];
    }

    #[DataProvider('lockedProperties')]
    public function test_locked_properties_cannot_be_set_from_the_client(string $property): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin)->test(Index::class)->set($property, '1');
    }

    public function test_the_show_component_keeps_its_id_locked(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin)
            ->test(Show::class, ['user' => (string) $this->admin->id])
            ->set('userId', 1);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badIds(): array
    {
        return [
            'text' => ['abc'],
            'array' => [[1]],
            'null' => [null],
            'negative' => [-1],
            'unknown' => [999999],
            'sql' => ['1 OR 1=1'],
        ];
    }

    #[DataProvider('badIds')]
    public function test_a_forged_id_is_a_404(mixed $id): void
    {
        foreach (['openEdit', 'openRole', 'openSuspend', 'openReactivate', 'openResend'] as $method) {
            Livewire::actingAs($this->admin)->test(Index::class)->call($method, $id)->assertNotFound();
        }
    }

    public function test_a_save_without_an_open_dialog_is_refused(): void
    {
        Livewire::actingAs($this->admin)->test(Index::class)->call('saveRole')->assertNotFound();
        Livewire::actingAs($this->admin)->test(Index::class)->call('confirm')->assertNotFound();
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $other = $this->hrAdmin();

        $this->assertRefused($this->admin, 'openRole', $this->admin->id);

        // A dialog opened for someone else cannot be pointed at oneself, and the model refuses too.
        $component = Livewire::actingAs($this->admin)->test(Index::class)
            ->call('openRole', $other->id)
            ->set('role', RoleName::Employee->value);

        $this->assertSame($other->id, $component->get('targetId'));
        $this->assertSame(RoleName::HrAdmin->value, $this->admin->fresh()->roleName());
    }

    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $this->hrAdmin();

        Livewire::actingAs($this->admin)->test(Index::class)->call('openSuspend', $this->admin->id)->assertForbidden();

        $this->assertSame(UserStatus::Active, $this->admin->fresh()->status);
        $this->assertSame(0, AuditLog::where('event', AuditEvent::UserSuspended->value)->count());
    }

    public function test_the_row_of_the_actor_offers_neither_role_change_nor_suspend(): void
    {
        $html = Livewire::actingAs($this->admin)->test(Index::class)->html();

        $this->assertStringContainsString('openEdit('.$this->admin->id.')', $html);
        $this->assertStringNotContainsString('openRole('.$this->admin->id.')', $html);
        $this->assertStringNotContainsString('openSuspend('.$this->admin->id.')', $html);
    }

    public function test_the_last_active_admin_cannot_demote_or_suspend_themselves(): void
    {
        $this->assertRefused($this->admin, 'openRole', $this->admin->id);
        $this->assertRefused($this->admin, 'openSuspend', $this->admin->id);

        $this->assertSame(UserStatus::Active, $this->admin->fresh()->status);
        $this->assertSame(RoleName::HrAdmin->value, $this->admin->fresh()->roleName());
    }

    /**
     * Two admins acting at once: the actor was suspended by the other while this request runs.
     * The in-memory actor still passes the checks, and the model's guard must stop it.
     */
    public function test_the_last_active_admin_is_protected_when_the_actor_just_lost_access(): void
    {
        $last = $this->hrAdmin();

        $suspend = Livewire::actingAs($this->admin)->test(Index::class)->call('openSuspend', $last->id);
        $demote = Livewire::actingAs($this->admin)->test(Index::class)
            ->call('openRole', $last->id)
            ->set('role', RoleName::Employee->value);

        User::whereKey($this->admin->id)->update(['status' => UserStatus::Suspended]);

        $suspend->call('confirm')->assertOk()->assertSee('The last active HR Admin cannot be suspended');
        $demote->call('saveRole')->assertOk()->assertSee('The last active HR Admin cannot be suspended');

        $this->assertSame(UserStatus::Active, $last->fresh()->status);
        $this->assertSame(RoleName::HrAdmin->value, $last->fresh()->roleName());
        $this->assertSame(0, AuditLog::where('event', AuditEvent::UserSuspended->value)->count());
    }

    /**
     * The model checks only the permission, so this limit rests on the policy check at confirmation.
     */
    public function test_a_confirmation_is_refused_when_the_target_gained_power_meanwhile(): void
    {
        $manager = $this->userWithRole(RoleName::Employee);
        $manager->givePermissionTo([PermissionName::UsersView, PermissionName::UsersSuspend]);

        $active = $this->userWithRole(RoleName::Employee);
        $suspended = User::factory()->suspended()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();

        $suspend = Livewire::actingAs($manager)->test(Index::class)->call('openSuspend', $active->id);
        $reactivate = Livewire::actingAs($manager)->test(Index::class)->call('openReactivate', $suspended->id);

        $active->changeRole(RoleName::HrAdmin, $this->admin);
        $suspended->changeRole(RoleName::HrAdmin, $this->admin);

        $suspend->call('confirm')->assertForbidden();
        $reactivate->call('confirm')->assertForbidden();

        $this->assertSame(UserStatus::Active, $active->fresh()->status);
        $this->assertSame(UserStatus::Suspended, $suspended->fresh()->status);
    }

    /**
     * A manager who can do everything in User Management except suspend, so HR Admin is out of reach.
     */
    private function limitedManager(): User
    {
        $manager = $this->userWithRole(RoleName::Approver);
        $manager->givePermissionTo([
            PermissionName::UsersView,
            PermissionName::UsersCreate,
            PermissionName::UsersInvite,
            PermissionName::UsersUpdate,
        ]);

        return $manager;
    }

    public function test_a_role_with_more_permissions_than_the_actor_cannot_be_given(): void
    {
        $target = $this->userWithRole(RoleName::Employee);

        Livewire::actingAs($this->limitedManager())->test(Index::class)
            ->call('openRole', $target->id)
            ->set('role', RoleName::HrAdmin->value)
            ->call('saveRole')
            ->assertForbidden();

        $this->assertSame(RoleName::Employee->value, $target->fresh()->roleName());
    }

    public function test_a_role_with_more_permissions_than_the_actor_cannot_be_given_at_creation(): void
    {
        Livewire::actingAs($this->limitedManager())->test(Index::class)
            ->call('openCreate')
            ->set('name', 'New Admin')
            ->set('username', 'new.admin')
            ->set('email', 'new.admin@example.com')
            ->set('role', RoleName::HrAdmin->value)
            ->call('saveUser')
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['username' => 'new.admin']);
    }

    public function test_a_role_within_the_actors_permissions_can_be_given(): void
    {
        $target = $this->userWithRole(RoleName::Employee);

        Livewire::actingAs($this->limitedManager())->test(Index::class)
            ->call('openRole', $target->id)
            ->set('role', RoleName::Approver->value)
            ->call('saveRole')
            ->assertOk();

        $this->assertSame(RoleName::Approver->value, $target->fresh()->roleName());
    }

    public function test_the_role_dropdowns_offer_only_roles_the_actor_may_give(): void
    {
        $html = Livewire::actingAs($this->limitedManager())->test(Index::class)->html();

        // The filter lists every role, and the two dialogs list only what can be given. Options are JSON in data-options since Phase 15b.
        $this->assertSame(1, substr_count($html, '&quot;value&quot;:&quot;'.RoleName::HrAdmin->value.'&quot;'));
        $this->assertSame(3, substr_count($html, '&quot;value&quot;:&quot;'.RoleName::Employee->value.'&quot;'));

        $adminHtml = Livewire::actingAs($this->admin)->test(Index::class)->html();

        $this->assertSame(3, substr_count($adminHtml, '&quot;value&quot;:&quot;'.RoleName::HrAdmin->value.'&quot;'));
    }

    public function test_an_account_with_more_power_than_the_actor_cannot_be_touched(): void
    {
        $manager = $this->limitedManager();
        $manager->givePermissionTo(PermissionName::UsersSuspend);
        $manager->revokePermissionTo(PermissionName::UsersInvite);

        $this->assertRefused($manager, 'openEdit', $this->admin->id);
        $this->assertRefused($manager, 'openRole', $this->admin->id);
        $this->assertRefused($manager, 'openSuspend', $this->admin->id);

        $suspended = User::factory()->suspended()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create();

        $this->assertRefused($manager, 'openReactivate', $suspended->id);

        $pending = User::factory()->pending()->withRole(RoleName::HrAdmin)->create();

        $this->assertRefused($this->limitedManager(), 'openResend', $pending->id);
    }

    public function test_a_suspended_user_is_refused_on_the_next_request(): void
    {
        $user = $this->userWithRole(RoleName::HrAdmin);

        $this->actingAs($user)->get(route('users.index'))->assertOk();

        $user->suspend();

        $this->startNextRequest();

        $this->get(route('users.index'))->assertRedirect(route('login'));
    }
}
