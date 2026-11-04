<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\RecoveryCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\LogsIn;
use Tests\Concerns\SeedsSessions;
use Tests\TestCase;

class SecuritySensitiveDataTest extends TestCase
{
    use LogsIn, RefreshDatabase, SeedsSessions;

    private const PASSWORD = 'Correct-Horse-42';

    private const NEW_PASSWORD = 'Brand-New-Horse-77';

    private User $user;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser(['password' => self::PASSWORD]);
        $this->secret = $this->user->two_factor_secret;

        $this->submitPassword($this->user, self::PASSWORD);
        $this->submitCode($this->totp($this->secret));
    }

    public function test_the_stored_secret_never_appears_in_any_page(): void
    {
        RecoveryCode::regenerateFor($this->user);
        $grouped = implode(' ', str_split($this->secret, 4));

        $pages = [];

        foreach (['dashboard', 'profile.show', 'security.index', 'profile.sessions', 'profile.login-history', 'security.confirm'] as $route) {
            $pages[] = $this->get(route($route))->assertOk()->getContent();
        }

        $this->confirmIdentity($this->user, self::PASSWORD);

        foreach (['profile.identity', 'security.password', 'security.recovery-codes', 'security.authenticator'] as $route) {
            $pages[] = $this->get(route($route))->assertOk()->getContent();
        }

        $pages[] = $this->post(route('security.recovery-codes.store'))->assertOk()->getContent();

        foreach ($pages as $page) {
            $this->assertStringNotContainsString($this->secret, $page);
            $this->assertStringNotContainsString($grouped, $page);
            $this->assertStringNotContainsString(rawurlencode($this->secret), $page);
        }
    }

    public function test_the_qr_code_carries_the_new_secret_not_the_stored_one(): void
    {
        $this->confirmIdentity($this->user, self::PASSWORD);

        $html = $this->get(route('security.authenticator'))->getContent();
        preg_match('/font-mono[^>]*>([A-Z2-7 ]+)</', $html, $matches);

        $this->assertNotSame($this->secret, str_replace(' ', '', $matches[1]));
    }

    public function test_a_full_security_cycle_leaves_no_sensitive_value_in_logs_or_records(): void
    {
        $logged = '';
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged .= $event->message.json_encode($event->context);
        });

        $oldCodes = RecoveryCode::regenerateFor($this->user);

        // Failures first, each with a recognizable value.
        $this->travel(30)->seconds();
        $this->post(route('security.confirm.store'), ['password' => 'Wrong-Horse-99', 'code' => '123456']);
        $this->post(route('security.confirm.store'), ['password' => self::PASSWORD, 'recovery_code' => 'WRONG-RECOVERY-CODE']);
        $this->post(route('security.confirm.store'), ['password' => self::PASSWORD, 'code' => '654321']);

        // A recovery code proves identity for the password change.
        $this->post(route('security.confirm.store'), ['password' => self::PASSWORD, 'recovery_code' => $oldCodes[0]]);
        $this->post(route('security.password.update'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);

        // Reconfigure, with a wrong code on the way.
        $this->confirmIdentity($this->user, self::NEW_PASSWORD);
        $html = $this->get(route('security.authenticator'))->getContent();
        preg_match('/font-mono[^>]*>([A-Z2-7 ]+)</', $html, $matches);
        $newSecret = str_replace(' ', '', $matches[1]);
        $wrongCode = $this->totp($newSecret, 5);
        $this->post(route('security.authenticator.store'), ['code' => $wrongCode]);
        $confirmationCode = $this->totp($newSecret);
        $page = $this->post(route('security.authenticator.store'), ['code' => $confirmationCode])->getContent();
        preg_match_all('/<li>([A-Z2-9-]{19})<\/li>/', $page, $codes);

        $this->assertCount(10, $codes[1]);

        // Regenerate, in a new request that sees the new secret.
        $this->startNextRequest();
        $this->confirmIdentity($this->user->fresh(), self::NEW_PASSWORD);
        $regenerated = $this->post(route('security.recovery-codes.store'))->getContent();
        preg_match_all('/<li>([A-Z2-9-]{19})<\/li>/', $regenerated, $lastCodes);

        $this->assertCount(10, $lastCodes[1]);

        $records = AuditLog::all()->toJson()
            .LoginHistory::all()->toJson()
            .DB::table('recovery_codes')->get()->toJson();

        $sensitive = [
            self::PASSWORD,
            self::NEW_PASSWORD,
            'Wrong-Horse-99',
            '123456',
            '654321',
            'WRONG-RECOVERY-CODE',
            $this->secret,
            $newSecret,
            $wrongCode,
            $confirmationCode,
            ...$oldCodes,
            ...$codes[1],
            ...$lastCodes[1],
        ];

        foreach ($sensitive as $value) {
            $this->assertStringNotContainsString($value, $logged);
            $this->assertStringNotContainsString($value, $records);
        }

        $this->assertStringNotContainsString($newSecret, json_encode(session()->all()));
    }

    public function test_sensitive_input_is_never_flashed_back(): void
    {
        $this->from(route('security.confirm'))->post(route('security.confirm.store'), [
            'password' => 'Wrong-Horse-99',
            'code' => 'abc',
            'recovery_code' => str_repeat('A', 65),
        ]);

        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('code'));
        $this->assertNull(session()->getOldInput('recovery_code'));
    }

    public function test_the_session_page_snapshot_holds_no_handle_or_id(): void
    {
        $this->seedSession($this->user, 'otherdeviceotherdeviceotherdeviceotherde');

        $html = $this->get(route('profile.sessions'))->getContent();

        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

        $this->assertStringNotContainsString('otherdevice', html_entity_decode($matches[1]));
        $this->assertStringNotContainsString('otherdevice', $html);
    }

    public function test_the_two_factor_routes_do_not_expose_the_user_id(): void
    {
        foreach (['security.confirm', 'security.index', 'profile.sessions'] as $route) {
            $this->assertStringNotContainsString('/'.$this->user->id, route($route));
        }
    }
}
