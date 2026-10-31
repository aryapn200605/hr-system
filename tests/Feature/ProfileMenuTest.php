<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();
    }

    public function test_sessions_and_login_history_live_under_my_profile(): void
    {
        foreach (['profile.sessions', 'profile.login-history'] as $route) {
            $this->actingAs($this->user)->get(route($route))
                ->assertOk()
                ->assertSeeInOrder(['Home', 'My Profile'])
                ->assertSee(route('profile.show'), false)
                ->assertSee('aria-current="page"', false);
        }
    }

    public function test_my_profile_links_to_both_pages(): void
    {
        $this->actingAs($this->user)->get(route('profile.show'))
            ->assertOk()
            ->assertSee(route('profile.sessions'), false)
            ->assertSee(route('profile.login-history'), false);
    }

    public function test_the_old_addresses_are_gone(): void
    {
        $this->actingAs($this->user)->get('/security/sessions')->assertNotFound();
        $this->actingAs($this->user)->get('/security/login-history')->assertNotFound();
    }

    public function test_security_settings_no_longer_lead_to_them(): void
    {
        $this->actingAs($this->user)->get(route('security.index'))
            ->assertOk()
            ->assertDontSee(route('profile.sessions'), false)
            ->assertDontSee(route('profile.login-history'), false);
    }

    public function test_no_route_or_view_can_reset_two_factor(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase('reset-two-factor', $route->uri());
            $this->assertStringNotContainsStringIgnoringCase('two-factor.reset', (string) $route->getName());
        }

        foreach ([app_path('Livewire'), app_path('Http'), resource_path('views')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                $this->assertStringNotContainsString('resetTwoFactor', $file->getContents(), $file->getRelativePathname());
            }
        }
    }
}
