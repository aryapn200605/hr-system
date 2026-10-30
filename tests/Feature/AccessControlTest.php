<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Livewire;
use stdClass;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class AccessControlProbe extends Component
{
    public static int $calls = 0;

    public function touch(): void
    {
        static::$calls++;
    }

    public function render(): string
    {
        return '<div>probe</div>';
    }
}

class AccessControlTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    /**
     * Routes outside the full-login check. Anything new must be added here on purpose.
     */
    private const PUBLIC_ROUTE_NAMES = [
        'login',
        'login.store',
        'two-factor.cancel',
        'two-factor.challenge',
        'two-factor.challenge.store',
        'two-factor.recovery.store',
        'two-factor.enrollment',
        'two-factor.enrollment.store',
        'livewire.preview-file',
        'livewire.upload-file',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();

        AccessControlProbe::$calls = 0;
        Livewire::component('access-control-probe', AccessControlProbe::class);
    }

    /**
     * Render the probe for a logged-in user and return its snapshot, as a browser would hold it.
     */
    private function probeSnapshot(): string
    {
        Route::middleware(['web', 'fully.authenticated'])
            ->get('/test-probe', fn () => Blade::render('<livewire:access-control-probe />'));

        $html = $this->get('/test-probe')->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

        return html_entity_decode($matches[1]);
    }

    /**
     * Call an action through the real update endpoint.
     */
    private function callProbeAction(string $snapshot): TestResponse
    {
        return $this->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => new stdClass,
                'calls' => [['method' => 'touch', 'params' => [], 'metadata' => new stdClass]],
            ]],
        ], ['X-Livewire' => '1']);
    }

    public function test_the_probe_action_works_for_a_fully_logged_in_user(): void
    {
        $this->logIn($this->user);
        $snapshot = $this->probeSnapshot();

        $this->callProbeAction($snapshot)->assertOk();

        $this->assertSame(1, AccessControlProbe::$calls);
    }

    public function test_the_dashboard_refuses_a_guest(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_dashboard_refuses_a_user_who_only_passed_the_password_stage(): void
    {
        $this->submitPassword($this->user);

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_dashboard_refuses_a_user_whose_enrollment_is_incomplete(): void
    {
        $user = User::factory()->withRole(RoleName::HrAdmin)->create();

        $this->submitPassword($user);
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        // Even a guard login, which the application itself never does, gets no further.
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_fully_logged_in_user_reaches_the_dashboard(): void
    {
        $this->logIn($this->user);

        $this->get(route('dashboard'))->assertOk()->assertSee($this->user->name);
    }

    public function test_a_user_without_the_permission_gets_no_dashboard(): void
    {
        $user = User::factory()->twoFactorEnrolled()->create();

        $this->logIn($user);

        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_livewire_actions_refuse_a_guest(): void
    {
        $this->logIn($this->user);
        $snapshot = $this->probeSnapshot();
        $this->post(route('logout'));
        $this->startNextRequest();

        $this->callProbeAction($snapshot)->assertUnauthorized();

        $this->assertSame(0, AccessControlProbe::$calls);
    }

    public function test_livewire_actions_refuse_a_user_who_only_passed_the_password_stage(): void
    {
        $this->logIn($this->user);
        $snapshot = $this->probeSnapshot();
        $this->post(route('logout'));
        $this->startNextRequest();
        $this->travel(30)->seconds();

        $this->submitPassword($this->user)->assertRedirect(route('two-factor.challenge'));

        $this->callProbeAction($snapshot)->assertUnauthorized();

        $this->assertSame(0, AccessControlProbe::$calls);
    }

    public function test_livewire_actions_refuse_a_user_suspended_mid_session(): void
    {
        $this->logIn($this->user);
        $snapshot = $this->probeSnapshot();

        $this->user->suspend();
        $this->startNextRequest();

        $this->callProbeAction($snapshot)->assertUnauthorized();

        $this->assertSame(0, AccessControlProbe::$calls);
        $this->assertGuest();
    }

    public function test_a_user_suspended_mid_session_loses_access_at_once_and_the_session_ends(): void
    {
        $this->logIn($this->user);
        $this->get(route('dashboard'))->assertOk();

        $this->user->suspend();
        $this->startNextRequest();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->startNextRequest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_every_route_requires_a_full_login_unless_explicitly_public(): void
    {
        $unprotected = collect(Route::getRoutes()->getRoutes())
            ->reject(fn (IlluminateRoute $route) => in_array($route->getName(), self::PUBLIC_ROUTE_NAMES, true))
            ->filter(fn (IlluminateRoute $route) => in_array('web', $route->gatherMiddleware(), true))
            ->reject(fn (IlluminateRoute $route) => in_array('fully.authenticated', $route->gatherMiddleware(), true))
            ->map(fn (IlluminateRoute $route) => $route->uri())
            ->values();

        $this->assertSame([], $unprotected->all());
    }

    public function test_the_route_serving_livewire_updates_has_the_full_login_check_and_csrf(): void
    {
        $route = Route::getRoutes()->match(request()->create(Livewire::getUpdateUri(), 'POST'));

        $this->assertSame('livewire.update', $route->getName());
        $this->assertContains('fully.authenticated', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, Route::getMiddlewareGroups()['web']);
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_livewire_uploads_are_closed_to_anyone_without_a_signed_url(): void
    {
        $this->post(route('livewire.upload-file'))->assertUnauthorized();
    }

    public function test_every_login_form_route_is_in_the_web_group(): void
    {
        foreach (self::PUBLIC_ROUTE_NAMES as $name) {
            $this->assertContains('web', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $this->assertContains('web', Route::getRoutes()->getByName('logout')->gatherMiddleware());
    }

    public function test_logout_needs_post_and_ends_the_session(): void
    {
        $this->logIn($this->user);
        $this->get('/logout')->assertMethodNotAllowed();
        $this->assertAuthenticatedAs($this->user);

        $token = session()->token();
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($token, session()->token());
        $this->assertFalse(session()->has('login_web_'.sha1('Illuminate\Auth\SessionGuard')));
        $this->startNextRequest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_session_cookies_are_configured_safely(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertTrue((bool) config('session.encrypt'));
    }
}
