<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Real race: pruning in several processes while another process activates an account.
 * No RefreshDatabase, because its open transaction would hide the rows from the other processes.
 */
class PruneConcurrencyTest extends TestCase
{
    private const PRUNERS = 4;

    /**
     * @var list<int>
     */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::table('audit_logs')->whereIn('actor_id', $this->userIds)->orWhereIn('subject_id', $this->userIds)->delete();
        DB::table('account_invitations')->whereIn('user_id', $this->userIds)->delete();
        DB::table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function track(User $user): User
    {
        $this->userIds[] = $user->id;

        return $user;
    }

    public function test_pruning_during_an_activation_keeps_the_open_invitation_and_removes_only_old_ones(): void
    {
        $admin = $this->track(User::factory()->twoFactorEnrolled()->create(['status' => UserStatus::Active]));
        $invitee = $this->track(User::factory()->pending()->create());
        [$invitation] = AccountInvitation::issueFor($invitee, $admin);

        $old = [];
        foreach (range(1, 3) as $i) {
            $old[] = AccountInvitation::create([
                'user_id' => $this->track(User::factory()->pending()->create())->id,
                'invited_by' => $admin->id,
                'token_hash' => hash('sha256', random_bytes(16)),
                'expires_at' => now()->subDays(60),
            ])->id;
        }

        $startAt = microtime(true) + 4;
        $pool = Process::pool(function ($pool) use ($invitee, $invitation, $startAt) {
            $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'hr_system_test'];
            $pool->path(base_path())->env($env)
                ->command([PHP_BINARY, 'tests/Support/race.php', 'activation', (string) $invitee->id, (string) $invitation->id, (string) $startAt]);

            foreach (range(1, self::PRUNERS) as $pruner) {
                $pool->path(base_path())->env($env)
                    ->command([PHP_BINARY, 'tests/Support/race.php', 'prune', (string) $invitee->id, '0', (string) $startAt]);
            }
        })->start()->wait();

        $outcomes = collect($pool->collect())->map(function ($result) {
            $this->assertTrue($result->successful(), $result->errorOutput());

            return json_decode($result->output(), true);
        })->values();

        $this->assertTrue($outcomes->every(fn (array $o) => $o['reached_barrier']), 'A competitor started late, so this run proved nothing.');
        $this->assertTrue($outcomes->every(fn (array $o) => $o['won']), 'Activation and every prune run succeed.');

        $this->assertSame(UserStatus::Active, $invitee->fresh()->status);
        $this->assertNotNull($invitation->fresh()?->accepted_at, 'The just-used invitation is kept.');
        $this->assertSame(0, AccountInvitation::whereIn('id', $old)->count());
    }
}
