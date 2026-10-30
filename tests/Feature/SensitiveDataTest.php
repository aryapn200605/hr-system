<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class SensitiveDataTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    public function test_a_complete_login_cycle_leaves_no_sensitive_value_in_logs_or_records(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $logged = '';
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged .= $event->message.json_encode($event->context);
        });

        $password = 'Correct-Horse-42';
        $user = User::factory()->withRole(RoleName::Employee)->create(['password' => $password]);

        // Failures first, each with a recognizable value.
        $this->post(route('login.store'), ['identifier' => $user->username, 'password' => 'Wrong-Horse-99']);

        // Enrollment.
        $this->submitPassword($user, $password);
        $html = $this->get(route('two-factor.enrollment'))->getContent();
        preg_match('/font-mono[^>]*>([A-Z2-7 ]+)</', $html, $matches);
        $secret = str_replace(' ', '', $matches[1]);

        $wrongEnrollmentCode = $this->totp($secret, 5);
        $this->from(route('two-factor.enrollment'))->post(route('two-factor.enrollment.store'), ['code' => $wrongEnrollmentCode]);

        $enrollmentCode = $this->totp($secret);
        $page = $this->post(route('two-factor.enrollment.store'), ['code' => $enrollmentCode])->getContent();
        preg_match_all('/<li>([A-Z2-9-]{19})<\/li>/', $page, $codes);
        $recoveryCodes = $codes[1];

        $this->post(route('logout'));
        $this->startNextRequest();

        // A wrong code, a used code and a recovery code.
        $this->submitPassword($user, $password);
        $this->submitCode($enrollmentCode);
        $this->submitRecoveryCode('WRONG-RECOVERY-CODE');
        $this->submitRecoveryCode($recoveryCodes[0])->assertRedirect(route('dashboard'));

        $this->assertCount(10, $recoveryCodes);

        $records = AuditLog::all()->toJson()
            .LoginHistory::all()->toJson()
            .DB::table('recovery_codes')->get()->toJson();

        $sensitive = [
            $password,
            'Wrong-Horse-99',
            $secret,
            $enrollmentCode,
            $wrongEnrollmentCode,
            'WRONG-RECOVERY-CODE',
            ...$recoveryCodes,
        ];

        foreach ($sensitive as $value) {
            $this->assertStringNotContainsString($value, $logged);
            $this->assertStringNotContainsString($value, $records);
        }

        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
    }

    public function test_sensitive_attributes_stay_hidden_when_serialized(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->enrolledUser();
        $user->forceFill(['two_factor_last_timestep' => 123])->save();

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_last_timestep', $user->toArray());
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_sensitive_input_is_never_flashed_back(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->enrolledUser();
        $this->submitPassword($user);

        $this->from(route('two-factor.challenge'))->post(route('two-factor.challenge.store'), ['code' => 'abc']);
        $this->assertNull(session()->getOldInput('code'));

        $this->from(route('two-factor.challenge'))->post(route('two-factor.recovery.store'), ['recovery_code' => str_repeat('A', 65)]);
        $this->assertNull(session()->getOldInput('recovery_code'));
    }
}
