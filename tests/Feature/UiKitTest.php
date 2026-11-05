<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The component gallery exists only when APP_ENV is local.
 */
class UiKitTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create(['status' => UserStatus::Active]);
    }

    private function environment(string $name): void
    {
        $this->app['env'] = $name;
        config(['app.env' => $name]);
    }

    public function test_it_shows_the_gallery_when_the_environment_is_local(): void
    {
        $this->environment('local');

        $this->actingAs($this->user())->get('/ui-kit')
            ->assertOk()
            ->assertSee('Component gallery')
            ->assertSeeHtml('data-page')
            ->assertSeeHtml('data-kit="buttons"')
            ->assertSeeHtml('data-kit="fields"')
            ->assertSeeHtml('aria-labelledby="kit-dialog-title"')
            // The sample error of the gallery, linked to its field.
            ->assertSeeHtml('aria-describedby="kit-error-error"');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherEnvironments(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
            'testing' => ['testing'],
        ];
    }

    #[DataProvider('otherEnvironments')]
    public function test_it_is_not_found_in_any_other_environment(string $environment): void
    {
        // The user first: the seeder asks for confirmation in production.
        $user = $this->user();
        $this->environment($environment);

        $this->actingAs($user)->get('/ui-kit')
            ->assertNotFound()
            ->assertDontSee('Component gallery');
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->environment('local');

        $this->get('/ui-kit')->assertRedirect(route('login'));
    }

    public function test_the_gallery_is_in_no_menu(): void
    {
        $this->environment('local');

        $this->actingAs($this->user())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(url('/ui-kit'));
    }
}
