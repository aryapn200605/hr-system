<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Livewire\Profile\Show;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = $this->enrolledUser(['name' => 'Rina Karyawan']);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function roles(): array
    {
        return array_map(fn (RoleName $role) => [$role], array_combine(
            array_column(RoleName::cases(), 'name'),
            RoleName::cases(),
        ));
    }

    #[DataProvider('roles')]
    public function test_every_role_sees_its_own_details(RoleName $role): void
    {
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create();

        $this->actingAs($user)->get(route('profile.show'))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee($user->username)
            ->assertSee($user->email)
            ->assertSee($role->value)
            ->assertSee('Active')
            ->assertSee($user->created_at->format('j M Y'));
    }

    public function test_the_name_changes_with_a_full_page_reload_and_an_audit_entry(): void
    {
        Livewire::actingAs($this->user)->test(Show::class)
            ->assertSet('name', 'Rina Karyawan')
            ->set('name', 'Rina Baru')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('profile.show'));

        $this->assertSame('Rina Baru', $this->user->fresh()->name);

        $entry = AuditLog::where('event', AuditEvent::UserUpdated->value)->sole();

        $this->assertSame(['fields' => ['name']], $entry->metadata);
        $this->assertSame($this->user->id, $entry->actor_id);
    }

    public function test_an_unchanged_name_writes_no_audit_entry(): void
    {
        Livewire::actingAs($this->user)->test(Show::class)->call('save');

        $this->assertSame(0, AuditLog::where('event', AuditEvent::UserUpdated->value)->count());
    }

    public function test_the_name_needs_no_re_authentication(): void
    {
        $this->assertFalse(session()->has('reauthentication'));

        Livewire::actingAs($this->user)->test(Show::class)->set('name', 'Rina Baru')->call('save')->assertHasNoErrors();

        $this->assertSame('Rina Baru', $this->user->fresh()->name);
    }

    public function test_the_name_is_validated(): void
    {
        Livewire::actingAs($this->user)->test(Show::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors('name');

        Livewire::actingAs($this->user)->test(Show::class)
            ->set('name', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame('Rina Karyawan', $this->user->fresh()->name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenFields(): array
    {
        return [
            'username' => ['username'],
            'email' => ['email'],
            'status' => ['status'],
            'password' => ['password'],
        ];
    }

    #[DataProvider('forbiddenFields')]
    public function test_a_payload_for_another_field_fails(string $field): void
    {
        $this->expectException(PublicPropertyNotFoundException::class);

        try {
            Livewire::actingAs($this->user)->test(Show::class)->set($field, 'changed@example.com');
        } finally {
            $fresh = $this->user->fresh();

            $this->assertSame($this->user->username, $fresh->username);
            $this->assertSame($this->user->email, $fresh->email);
        }
    }

    public function test_a_payload_with_extra_keys_changes_only_the_name(): void
    {
        Livewire::actingAs($this->user)->test(Show::class)
            ->set('name', 'Rina Baru')
            ->call('save', ['username' => 'evil', 'email' => 'evil@example.com']);

        $fresh = $this->user->fresh();

        $this->assertSame('Rina Baru', $fresh->name);
        $this->assertSame($this->user->username, $fresh->username);
        $this->assertSame($this->user->email, $fresh->email);
    }

    public function test_a_name_that_is_not_a_string_is_refused(): void
    {
        Livewire::actingAs($this->user)->test(Show::class)->set('name', ['nested'])->assertStatus(422);

        $this->assertSame('Rina Karyawan', $this->user->fresh()->name);
    }

    public function test_the_page_links_to_the_identity_form_and_holds_no_identity_inputs(): void
    {
        $this->actingAs($this->user)->get(route('profile.show'))
            ->assertSee(route('profile.identity'))
            ->assertDontSee('wire:model="username"', false)
            ->assertDontSee('wire:model="email"', false);
    }
}
