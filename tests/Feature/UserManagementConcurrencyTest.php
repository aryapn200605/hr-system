<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Real races: separate PHP processes with separate connections, on committed rows.
 *
 * Like AuthConcurrencyTest, this class cannot use RefreshDatabase and cleans up after itself,
 * including the seeded roles, which other tests expect to be absent until they seed them.
 */
class UserManagementConcurrencyTest extends TestCase
{
    private const ROUNDS = 3;

    /**
     * @var list<int>
     */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        DB::table('audit_logs')
            ->whereIn('actor_id', $this->userIds)
            ->orWhereIn('subject_id', $this->userIds)
            ->delete();
        DB::table('users')->whereIn('id', $this->userIds)->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();

        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create();

        $this->userIds[] = $admin->id;

        return $admin;
    }

    private function activeAdmins(): int
    {
        return User::query()->activeHrAdmin()->count();
    }

    /**
     * Run one process per pair of IDs at the same moment and return how many won.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $moves  mode, subject ID, actor or invitation ID
     */
    private function race(array $moves): int
    {
        $startAt = microtime(true) + 3;

        $pool = Process::pool(function ($pool) use ($moves, $startAt) {
            foreach ($moves as [$mode, $subject, $other]) {
                $pool->path(base_path())->env([
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => 'hr_system_test',
                ])->command([PHP_BINARY, 'tests/Support/race.php', $mode, (string) $subject, (string) $other, (string) $startAt]);
            }
        })->start()->wait();

        $outcomes = collect($pool->collect())->map(function ($result) {
            $this->assertTrue($result->successful(), $result->errorOutput());

            return json_decode($result->output(), true);
        });

        $this->assertCount(count($moves), $outcomes);
        $this->assertTrue(
            $outcomes->every(fn (array $outcome) => $outcome['reached_barrier']),
            'A competitor started late, so this run proved nothing.',
        );

        return $outcomes->where('won', true)->count();
    }

    public function test_two_admins_suspending_each_other_leave_one_active_admin(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $first = $this->admin();
            $second = $this->admin();

            $winners = $this->race([
                ['suspend', $second->id, $first->id],
                ['suspend', $first->id, $second->id],
            ]);

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(1, $this->activeAdmins(), 'Round '.$round);

            // The next round starts from two fresh admins, with no survivor counted.
            User::query()->whereIn('id', [$first->id, $second->id])->update(['status' => UserStatus::Suspended]);
        }

        $this->assertSame(self::ROUNDS, AuditLog::where('event', AuditEvent::UserSuspended->value)->count());
    }

    public function test_a_ring_of_admins_suspending_each_other_never_runs_out(): void
    {
        $admins = collect(range(1, 4))->map(fn () => $this->admin());

        $moves = $admins->map(fn (User $actor, int $index) => [
            'suspend',
            $admins[($index + 1) % $admins->count()]->id,
            $actor->id,
        ])->all();

        $this->assertGreaterThanOrEqual(1, $this->race($moves));
        $this->assertGreaterThanOrEqual(1, $this->activeAdmins());
    }

    public function test_suspending_and_demoting_at_once_leave_one_active_admin(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $first = $this->admin();
            $second = $this->admin();

            $this->race([
                ['suspend', $second->id, $first->id],
                ['demote', $first->id, $second->id],
            ]);

            $this->assertSame(1, $this->activeAdmins(), 'Round '.$round);

            User::query()->whereIn('id', [$first->id, $second->id])->update(['status' => UserStatus::Suspended]);
        }
    }

    public function test_a_pending_account_ends_either_suspended_or_active_under_a_race(): void
    {
        $admin = $this->admin();

        foreach (range(1, self::ROUNDS) as $round) {
            $invitee = User::factory()->pending()->withRole(RoleName::Employee)->create();
            $this->userIds[] = $invitee->id;

            [$invitation] = AccountInvitation::issueFor($invitee, $admin);

            $this->race([
                ['activation', $invitee->id, $invitation->id],
                ['suspend', $invitee->id, $admin->id],
            ]);

            $fresh = $invitee->fresh();

            // The suspension always lands, and an activation it beat leaves nothing behind.
            $this->assertSame(UserStatus::Suspended, $fresh->status, 'Round '.$round);

            if ($invitation->fresh()->accepted_at === null) {
                $this->assertNull($fresh->password, 'Round '.$round);
            }
        }
    }
}
