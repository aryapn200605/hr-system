<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 15e: the error pages (403, 404, 419, 429, 500, 503 and the 4xx/5xx fallbacks) show safe, fixed texts only,
 * with a way back (dashboard when logged in, login otherwise), no technical detail and no referrer; and a failed
 * Livewire action gets a dialog instead of Livewire's own confirm() or its raw-response frame.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'internal-detail-7f3a in /var/www/app/Secret.php:42';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['app.debug' => false]);

        // Test routes inside the web group, each failing with a message that must never be shown.
        Route::middleware('web')->group(function () {
            Route::get('/_test/abort/{code}', fn (int $code) => abort($code, self::SECRET));
            Route::get('/_test/exception', fn () => throw new RuntimeException(self::SECRET));
        });
    }

    private function user(RoleName $role = RoleName::Employee, array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create(['status' => UserStatus::Active] + $attributes);
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function pages(): array
    {
        return [
            '403' => [403, 'You do not have access to this page', 'Your role does not allow this page or action.'],
            '404' => [404, 'Page not found', 'The page you are looking for does not exist'],
            '419' => [419, 'Your session has expired', 'so the form was not sent'],
            '429' => [429, 'Too many attempts', 'Wait a minute, then try again.'],
            '500' => [500, 'Something went wrong', 'An unexpected problem stopped this page.'],
            '503' => [503, 'Down for maintenance', 'The system is being updated'],
        ];
    }

    private function assertSafePage(string $html, int $code): void
    {
        $this->assertStringContainsString('data-error-page="'.$code.'"', $html);
        $this->assertStringNotContainsString('internal-detail-7f3a', $html);
        $this->assertStringNotContainsString('Secret.php', $html);
        $this->assertStringNotContainsString('RuntimeException', $html);
        $this->assertStringNotContainsString('Stack trace', $html);
        // Standalone: no Livewire, no Alpine, no application script.
        $this->assertStringNotContainsString('livewire', strtolower($html));
        $this->assertStringNotContainsString('x-data', $html);
        $this->assertDoesNotMatchRegularExpression('#<script[^>]+src=#', $html);
        // The referrer policy comes before every request the page makes.
        $policy = strpos($html, '<meta name="referrer" content="no-referrer">');
        $this->assertNotFalse($policy);
        foreach (['<link', '<img', '<script'] as $tag) {
            $first = strpos($html, $tag);
            $this->assertTrue($first === false || $first > $policy, "$tag before the referrer policy");
        }
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<main id="main"', $html);
    }

    #[DataProvider('pages')]
    public function test_each_error_page_shows_its_safe_text_and_a_way_to_the_login_page(int $code, string $title, string $text): void
    {
        $response = $this->get('/_test/abort/'.$code)->assertStatus($code);
        $html = $response->getContent();

        $this->assertSafePage($html, $code);
        $this->assertStringContainsString('<title>'.$code.' · '.$title.' - ', $html);
        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString($text, $html);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('login'), '#').'"[^>]*data-error-home>.*?Go to login#s', $html);
        $this->assertStringNotContainsString(route('dashboard'), $html);
    }

    #[DataProvider('pages')]
    public function test_a_logged_in_user_is_sent_to_the_dashboard_in_their_language(int $code): void
    {
        $user = $this->user(attributes: ['locale' => 'id']);
        $html = $this->actingAs($user)->get('/_test/abort/'.$code)->assertStatus($code)->getContent();

        $this->assertSafePage($html, $code);
        $this->assertStringContainsString('<html lang="id">', $html);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('dashboard'), '#').'"[^>]*data-error-home>.*?Ke beranda#s', $html);
        $this->assertStringContainsString('Galat '.$code, $html);
    }

    public function test_retry_is_offered_only_where_reloading_helps(): void
    {
        foreach ([500, 503] as $code) {
            $this->assertMatchesRegularExpression('#<button type="button" class="hidden [^"]*" data-error-retry>#', $this->get('/_test/abort/'.$code)->getContent());
        }
        foreach ([403, 404, 419, 429] as $code) {
            $this->assertDoesNotMatchRegularExpression('#<button[^>]*data-error-retry>#', $this->get('/_test/abort/'.$code)->getContent());
        }
        // "Go back" is in every page, hidden until the script finds a page to go back to.
        $this->assertMatchesRegularExpression('#class="hidden [^"]*" data-error-back>#', $this->get('/_test/abort/404')->getContent());

        // Three buttons fit one row and their labels never wrap (seen wrapped at 1280 px before).
        $html = $this->get('/_test/abort/500')->getContent();
        $this->assertSame(3, preg_match_all('#<(?:a|button)\b[^>]*class="[^"]*\bwhitespace-nowrap\b[^"]*"[^>]*data-error-(?:back|retry|home)>#', $html));
        $this->assertStringContainsString('class="w-full max-w-lg rounded-2xl', $html);
    }

    public function test_an_unknown_address_gets_the_404_page_with_a_link_to_the_start_page(): void
    {
        // No route matches, so no session has started: who asks is unknown, and the start page decides
        // (a guest goes on to the login page, a user to the dashboard).
        $html = $this->get('/no/such/page')->assertNotFound()->getContent();
        $this->assertSafePage($html, 404);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(url('/'), '#').'"[^>]*data-error-home>.*?Go to the home page#s', $html);
        $this->get('/')->assertRedirect(route('login'));

        // A method a route does not take keeps its own answer (no fallback route swallows it).
        $this->get('/logout')->assertStatus(405);
    }

    public function test_a_real_exception_shows_the_500_page_without_its_message(): void
    {
        $html = $this->get('/_test/exception')->assertStatus(500)->getContent();

        $this->assertSafePage($html, 500);
        $this->assertStringContainsString('Something went wrong', $html);
    }

    public function test_other_codes_use_the_generic_pages(): void
    {
        $this->assertSafePage($this->get('/_test/abort/410')->assertStatus(410)->getContent(), 410);
        $this->assertStringContainsString('This request could not be completed', $this->get('/_test/abort/410')->getContent());
        $this->assertStringContainsString('Something went wrong', $this->get('/_test/abort/502')->assertStatus(502)->getContent());
        $this->assertStringContainsString('data-error-page="405"', $this->get('/logout')->assertStatus(405)->getContent());
    }

    public function test_the_page_still_renders_when_the_texts_cannot_be_loaded(): void
    {
        Lang::shouldReceive('get')->andThrow(new RuntimeException('database down'));

        $html = $this->get('/_test/abort/500')->assertStatus(500)->getContent();
        $this->assertSafePage($html, 500);
        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringNotContainsString('database down', $html);
    }

    public function test_real_triggers_reach_the_pages(): void
    {
        // 403: an Employee opening User Management.
        $html = $this->actingAs($this->user())->get(route('users.index'))->assertForbidden()->getContent();
        $this->assertSafePage($html, 403);
        $this->assertStringContainsString('Go to dashboard', $html);

        // 404: a request that is not one's own.
        $this->assertSafePage($this->get(route('requests.show', ['type' => 'leave', 'id' => 999999]))->assertNotFound()->getContent(), 404);
    }

    public function test_the_gallery_opens_each_page_with_its_real_status_in_local_only(): void
    {
        $user = $this->user();
        $this->app['env'] = 'local';
        config(['app.env' => 'local']);

        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $this->assertSafePage($this->actingAs($user)->get(route('ui-kit.error', $code))->assertStatus($code)->getContent(), $code);
        }
        $this->actingAs($user)->get(route('ui-kit.error', 418))->assertNotFound();

        $this->app['env'] = 'production';
        config(['app.env' => 'production']);
        $this->actingAs($user)->get(route('ui-kit.error', 500))->assertNotFound();
    }

    public function test_a_failed_livewire_action_opens_a_dialog_instead_of_the_raw_response(): void
    {
        $html = $this->actingAs($this->user())->get(route('dashboard'))->assertOk()->getContent();

        // 401 still goes to the login page; every other failure dispatches request-failed with its status.
        $this->assertMatchesRegularExpression('#if \(response\.status === 401\) \{\s*preventDefault\(\);\s*window\.location\.href = #', $html);
        $this->assertMatchesRegularExpression("#\} else if \(response\.status >= 400\) \{\s*preventDefault\(\);\s*window\.dispatchEvent\(new CustomEvent\('request-failed', \{ detail: \{ status: response\.status \} \}\)\);#", $html);

        foreach (['session-expired' => 'Your session has expired', 'request-denied' => 'This action is not available', 'request-throttled' => 'Too many attempts', 'request-failed' => 'Something went wrong'] as $name => $title) {
            $this->assertMatchesRegularExpression('#aria-labelledby="'.$name.'-title"#', $html);
            $this->assertMatchesRegularExpression('#<h2 id="'.$name.'-title"[^>]*>'.preg_quote($title, '#').'</h2>#', $html);
        }
        $this->assertStringContainsString("let name = status === 419 ? 'session-expired' : ([403, 404].includes(status) ? 'request-denied' : (status === 429 ? 'request-throttled' : 'request-failed'));", $html);
        $this->assertSame(3, preg_match_all('#<button[^>]*x-on:click="window\.location\.reload\(\)"[^>]*data-reload#', $html));
    }
}
