<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function pages(): array
    {
        return [
            '403' => [403, 'You do not have access to this page'],
            '404' => [404, 'Page not found'],
            '419' => [419, 'This page expired'],
            '429' => [429, 'Too many attempts'],
            '500' => [500, 'Something went wrong'],
            '503' => [503, 'Temporarily unavailable'],
            'other 4xx' => [405, 'This request could not be completed'],
            'other 5xx' => [502, 'Something went wrong'],
        ];
    }

    #[DataProvider('pages')]
    public function test_each_error_has_an_application_page(int $status, string $title): void
    {
        Route::get('/_test/error', fn () => abort($status));

        $this->get('/_test/error')
            ->assertStatus($status)
            ->assertSee($title)
            ->assertSee('Go to the home page')
            ->assertSee('<title>'.$title.' - '.config('app.name').'</title>', false)
            ->assertDontSee('Illuminate')
            ->assertDontSee('Symfony');
    }

    public function test_an_exception_message_never_reaches_the_page(): void
    {
        Route::get('/_test/error', fn () => throw new RuntimeException('secret-detail in /var/www/app.php'));

        $this->get('/_test/error')
            ->assertStatus(500)
            ->assertSee('Something went wrong')
            ->assertDontSee('secret-detail')
            ->assertDontSee('app.php');
    }

    public function test_an_abort_message_never_reaches_the_page(): void
    {
        Route::get('/_test/error', fn () => abort(403, 'secret-detail about policy'));

        $this->get('/_test/error')->assertForbidden()->assertDontSee('secret-detail');
    }

    public function test_a_missing_route_gets_the_page(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found');
    }

    public function test_a_real_authorization_denial_gets_the_page(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->actingAs($this->enrolledUser())->get('/users')
            ->assertForbidden()
            ->assertSee('You do not have access to this page');
    }

    public function test_a_livewire_request_still_gets_json_and_not_a_page(): void
    {
        Route::get('/_test/error', fn () => abort(404));

        $this->get('/_test/error', ['X-Livewire' => 'true'])
            ->assertNotFound()
            ->assertDontSee('Go to the home page');
    }
}
