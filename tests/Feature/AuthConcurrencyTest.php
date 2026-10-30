<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

/**
 * Real races: separate PHP processes with separate connections, on committed rows.
 *
 * This class cannot use RefreshDatabase, whose open transaction would hide the rows
 * from the other processes. It cleans up after itself instead.
 */
class AuthConcurrencyTest extends TestCase
{
    use LogsIn;

    private const COMPETITORS = 8;

    private User $user;

    private ?User $invitee = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);

        $this->user = User::factory()->twoFactorEnrolled()->create(['status' => UserStatus::Active]);
    }

    protected function tearDown(): void
    {
        $ids = array_filter([$this->user->id, $this->invitee?->id]);

        // Audit rows restrict the delete of their actor, and only this test may remove them.
        DB::table('audit_logs')->whereIn('actor_id', $ids)->delete();
        DB::table('users')->whereIn('id', $ids)->delete();

        parent::tearDown();
    }

    /**
     * Run the competitors at the same moment and return how many won.
     */
    private function race(string $mode, string $code, ?User $user = null): int
    {
        $startAt = microtime(true) + 4;
        $userId = (string) ($user ?? $this->user)->id;

        $pool = Process::pool(function ($pool) use ($mode, $code, $startAt, $userId) {
            foreach (range(1, self::COMPETITORS) as $competitor) {
                $pool->path(base_path())->env([
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => 'hr_system_test',
                ])->command([PHP_BINARY, 'tests/Support/race.php', $mode, $userId, $code, (string) $startAt]);
            }
        })->start()->wait();

        $outcomes = collect($pool->collect())->map(function ($result) {
            $this->assertTrue($result->successful(), $result->errorOutput());

            return json_decode($result->output(), true);
        });

        $this->assertCount(self::COMPETITORS, $outcomes);
        $this->assertTrue(
            $outcomes->every(fn (array $outcome) => $outcome['reached_barrier']),
            'A competitor started late, so this run proved nothing.',
        );

        return $outcomes->where('won', true)->count();
    }

    public function test_a_recovery_code_is_consumed_once_by_concurrent_requests(): void
    {
        $codes = RecoveryCode::regenerateFor($this->user);

        $winners = $this->race('recovery', $codes[0]);

        $this->assertSame(1, $winners);
        $this->assertSame(1, $this->user->recoveryCodes()->whereNotNull('used_at')->count());
        $this->assertSame(9, $this->user->recoveryCodes()->whereNull('used_at')->count());
    }

    public function test_a_totp_code_is_accepted_once_by_concurrent_requests(): void
    {
        $code = $this->totp($this->user->two_factor_secret);
        $timestep = (int) floor(now()->getTimestamp() / 30);

        // The race takes seconds, so the competitors must accept this code from their own clock.
        $this->assertNotNull(TwoFactor::matchingTimestep($this->user->two_factor_secret, $code));

        $winners = $this->race('totp', $code);

        $this->assertSame(1, $winners);
        $this->assertGreaterThanOrEqual($timestep, $this->user->fresh()->two_factor_last_timestep);
    }

    public function test_an_invitation_is_consumed_once_by_concurrent_requests(): void
    {
        $this->invitee = User::factory()->pending()->create();
        [$invitation] = AccountInvitation::issueFor($this->invitee, $this->user);

        $winners = $this->race('activation', (string) $invitation->id, $this->invitee);

        $invitee = $this->invitee->fresh();

        $this->assertSame(1, $winners);
        $this->assertSame(UserStatus::Active, $invitee->status);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame(10, $invitee->recoveryCodes()->count());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::InvitationConsumed->value)->where('subject_id', $invitee->id)->count());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::PasswordChanged->value)->where('subject_id', $invitee->id)->count());
    }
}
