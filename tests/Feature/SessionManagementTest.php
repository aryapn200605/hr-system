<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ActiveSessions;
use App\Support\UserAgentLabel;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Concerns\LogsIn;
use Tests\Concerns\SeedsSessions;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use LogsIn, RefreshDatabase, SeedsSessions;

    /**
     * The session ID the test client sends, so the application sees the same "current" session each time.
     */
    private const CURRENT = 'currentcurrentcurrentcurrentcurrentcurre';

    private const OTHER = 'otherdeviceotherdeviceotherdeviceotherde';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
        $this->logIn($this->user);
        $this->withCookie(config('session.cookie'), self::CURRENT)->withCredentials();

        // Older than the other session, so the list has to put it first on purpose.
        $this->seedSession($this->user, self::CURRENT, now()->getTimestamp() - 60);
        $this->seedSession($this->user, self::OTHER, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1');
    }

    /**
     * The sessions page as this browser sees it.
     */
    private function page(): TestResponse
    {
        return $this->get(route('security.sessions'))->assertOk();
    }

    /**
     * Call an action through the real update endpoint.
     *
     * @param  list<mixed>  $params
     */
    private function callAction(string $method, array $params = []): TestResponse
    {
        preg_match('/wire:snapshot="([^"]+)"/', $this->page()->getContent(), $matches);

        return $this->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1]),
                'updates' => new stdClass,
                'calls' => [['method' => $method, 'params' => $params, 'metadata' => new stdClass]],
            ]],
        ], ['X-Livewire' => '1']);
    }

    /**
     * The handle the page gives a session.
     */
    private function rawHandle(string $sessionId): string
    {
        $method = new \ReflectionMethod(ActiveSessions::class, 'handle');

        return $method->invoke(null, $sessionId);
    }

    /**
     * @return list<string>
     */
    private function remaining(): array
    {
        return DB::table('sessions')->orderBy('id')->pluck('id')->all();
    }

    public function test_the_page_lists_own_sessions_and_marks_the_current_one(): void
    {
        $this->page()
            ->assertSee('Chrome on Windows')
            ->assertSee('Safari on iOS')
            ->assertSee('203.0.113.7')
            ->assertSee('This session')
            ->assertSeeInOrder(['Chrome on Windows', 'This session', 'Safari on iOS']);
    }

    public function test_the_list_shows_nobody_elses_sessions(): void
    {
        $other = $this->enrolledUser();
        $this->seedSession($other, 'not-mine', userAgent: 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0');

        $this->page()->assertDontSee('Firefox on Linux');
    }

    public function test_expired_rows_are_not_listed(): void
    {
        $lifetime = config('session.lifetime') * 60;

        $this->seedSession($this->user, 'expired', now()->getTimestamp() - $lifetime - 1, 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0');
        $this->seedSession($this->user, 'barely', now()->getTimestamp() - $lifetime, 'Mozilla/5.0 (X11; Linux x86_64; Android 14) Firefox/120.0');

        $this->page()->assertDontSee('Firefox on Linux')->assertSee('Firefox on Android');
    }

    public function test_the_page_never_holds_a_session_id_or_payload(): void
    {
        $html = $this->page()->getContent();

        $this->assertStringNotContainsString(self::OTHER, $html);
        $this->assertStringNotContainsString(self::CURRENT, $html);
        $this->assertStringNotContainsString('unreadable', $html);
    }

    public function test_the_current_session_gets_no_handle_and_no_button(): void
    {
        $sessions = ActiveSessions::listFor($this->user, self::CURRENT);

        $this->assertNull($sessions->firstWhere('current', true)['handle']);
        // One for the other session and one in the dialog.
        $this->assertSame(2, preg_match_all('/End session\s*<\/button>/', $this->page()->getContent()));
    }

    public function test_the_button_of_another_session_carries_its_handle(): void
    {
        $this->page()
            ->assertSee("handle = '".$this->rawHandle(self::OTHER)."'", false)
            ->assertDontSee('@js', false);
    }

    public function test_one_other_session_can_be_ended(): void
    {
        $this->seedSession($this->user, 'tablet');

        $this->callAction('terminate', [$this->rawHandle(self::OTHER)])->assertOk();

        $this->assertSame([self::CURRENT, 'tablet'], $this->remaining());

        $entry = AuditLog::where('event', AuditEvent::SessionTerminated->value)->sole();

        $this->assertSame(['scope' => 'single', 'sessions_ended' => 1], $entry->metadata);
        $this->assertStringNotContainsString(self::OTHER, $entry->toJson());
    }

    public function test_all_other_sessions_can_be_ended_and_nobody_elses(): void
    {
        $other = $this->enrolledUser();
        $this->seedSession($this->user, 'tablet');
        $this->seedSession($other, 'not-mine');

        $this->callAction('terminateOthers')->assertOk();

        $this->assertSame([self::CURRENT, 'not-mine'], $this->remaining());

        $entry = AuditLog::where('event', AuditEvent::SessionTerminated->value)->sole();

        $this->assertSame(['scope' => 'others', 'sessions_ended' => 2], $entry->metadata);
    }

    public function test_ending_nothing_writes_no_audit_entry(): void
    {
        DB::table('sessions')->where('id', self::OTHER)->delete();

        $this->callAction('terminateOthers')->assertOk();

        $this->assertSame(0, AuditLog::where('event', AuditEvent::SessionTerminated->value)->count());
    }

    public function test_a_handle_of_another_users_session_hits_nothing(): void
    {
        $other = $this->enrolledUser();
        $this->seedSession($other, 'not-mine');

        $this->callAction('terminate', [$this->rawHandle('not-mine')])->assertOk();

        $this->assertContains('not-mine', $this->remaining());
        $this->assertSame(0, AuditLog::where('event', AuditEvent::SessionTerminated->value)->count());
    }

    public function test_a_raw_session_id_is_no_handle(): void
    {
        $other = $this->enrolledUser();
        $this->seedSession($other, 'not-mine');

        $this->callAction('terminate', ['not-mine'])->assertOk();
        $this->callAction('terminate', [self::OTHER])->assertOk();

        $this->assertContains('not-mine', $this->remaining());
        $this->assertContains(self::OTHER, $this->remaining());
    }

    public function test_a_handle_never_reaches_past_the_owner_even_when_called_directly(): void
    {
        $other = $this->enrolledUser();
        $this->seedSession($other, 'not-mine');

        $this->assertFalse(ActiveSessions::endOne($this->user, self::CURRENT, $this->rawHandle('not-mine')));
        $this->assertContains('not-mine', $this->remaining());
    }

    public function test_the_current_session_cannot_be_ended_through_a_handle(): void
    {
        $this->callAction('terminate', [$this->rawHandle(self::CURRENT)])->assertOk();

        $this->assertContains(self::CURRENT, $this->remaining());
        $this->assertSame(0, AuditLog::where('event', AuditEvent::SessionTerminated->value)->count());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function hostileHandles(): array
    {
        return [
            'array' => [['a']],
            'number' => [1],
            'null' => [null],
        ];
    }

    #[DataProvider('hostileHandles')]
    public function test_a_handle_must_be_a_string(mixed $handle): void
    {
        $this->callAction('terminate', [$handle])->assertStatus(422);

        $this->assertCount(2, $this->remaining());
    }

    public function test_a_request_in_flight_on_an_ended_session_cannot_bring_it_back(): void
    {
        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);

        // The in-flight request read its row before the session was ended.
        $handler->read(self::OTHER);

        $this->user->endOtherSessions(self::CURRENT);

        $handler->write(self::OTHER, 'written back');

        $this->assertSame([self::CURRENT], $this->remaining());
    }

    public function test_a_session_with_no_row_at_all_still_gets_one(): void
    {
        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);

        $handler->write('brand-new', 'payload');

        $this->assertContains('brand-new', $this->remaining());
    }

    public function test_confirming_identity_keeps_the_session_id(): void
    {
        $this->seedSession($this->user, 'x');
        $this->travel(30)->seconds();

        $this->post(route('security.confirm.store'), ['password' => 'password', 'code' => $this->totp($this->user->two_factor_secret)]);

        $this->assertSame(self::CURRENT, session()->getId());
    }

    public function test_the_page_offers_a_logout(): void
    {
        $this->page()->assertSee(route('logout'))->assertSee('Log out of this session');
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function userAgents(): array
    {
        return [
            'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', 'Chrome on Windows'],
            'edge windows' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0', 'Edge on Windows'],
            'opera mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0.0.0 Safari/537.36 OPR/105.0', 'Opera on macOS'],
            'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'Firefox on Linux'],
            'chrome android' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36', 'Chrome on Android'],
            'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'Safari on iOS'],
            'browser only' => ['Firefox/120.0', 'Firefox'],
            'system only' => ['curl (Windows)', 'Windows'],
            'unknown' => ['curl/8.0', 'Unknown device'],
            'empty' => ['', 'Unknown device'],
            'null' => [null, 'Unknown device'],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_a_user_agent_gets_a_short_label(?string $userAgent, string $label): void
    {
        $this->assertSame($label, UserAgentLabel::for($userAgent));
    }

    public function test_a_hostile_user_agent_is_escaped(): void
    {
        $this->seedSession($this->user, 'hostile', userAgent: '<script>alert(1)</script>');

        $this->page()->assertDontSee('<script>alert(1)</script>', false);
    }
}
