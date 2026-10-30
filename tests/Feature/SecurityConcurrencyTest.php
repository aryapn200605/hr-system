<?php

namespace Tests\Feature;

use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Real races: separate PHP processes with separate connections, on committed rows.
 *
 * Like AuthConcurrencyTest, this class cannot use RefreshDatabase and cleans up after itself.
 */
class SecurityConcurrencyTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::table('audit_logs')
            ->whereIn('actor_id', $this->userIds)
            ->orWhereIn('subject_id', $this->userIds)
            ->delete();
        DB::table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function user(): User
    {
        $user = User::factory()->twoFactorEnrolled()->create();

        $this->userIds[] = $user->id;

        return $user;
    }

    /**
     * Run one process per user at the same moment and return their outcomes.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, array{won: bool, reached_barrier: bool, invalid: bool, codes: list<string>}>
     */
    private function race(string $mode, array $userIds, string $argument): Collection
    {
        $startAt = microtime(true) + 3;

        $pool = Process::pool(function ($pool) use ($mode, $userIds, $argument, $startAt) {
            foreach ($userIds as $userId) {
                $pool->path(base_path())->env([
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => 'hr_system_test',
                ])->command([PHP_BINARY, 'tests/Support/race.php', $mode, (string) $userId, $argument, (string) $startAt]);
            }
        })->start()->wait();

        $outcomes = collect($pool->collect())->map(function ($result) {
            $this->assertTrue($result->successful(), $result->errorOutput());

            return json_decode($result->output(), true);
        })->values();

        $this->assertCount(count($userIds), $outcomes);
        $this->assertTrue(
            $outcomes->every(fn (array $outcome) => $outcome['reached_barrier']),
            'A competitor started late, so this run proved nothing.',
        );

        return $outcomes;
    }

    public function test_concurrent_regenerations_leave_exactly_one_valid_set(): void
    {
        $user = $this->user();

        $outcomes = $this->race('regenerate', array_fill(0, 8, $user->id), 'unused');

        $this->assertSame(10, $user->recoveryCodes()->count());

        $sets = $outcomes->pluck('codes');

        $this->assertTrue($sets->every(fn (array $codes) => count($codes) === 10));

        $valid = $sets->map(fn (array $codes) => collect($codes)
            ->filter(fn (string $code) => RecoveryCode::query()
                ->where('user_id', $user->id)
                ->where('code_hash', RecoveryCode::hashFor($user, $code))
                ->exists())
            ->count());

        $this->assertSame(1, $valid->filter(fn (int $count) => $count === 10)->count());
        $this->assertSame(7, $valid->filter(fn (int $count) => $count === 0)->count());
    }

    public function test_two_changes_to_one_email_give_one_success_and_one_validation_error(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $first = $this->user();
            $second = $this->user();
            $email = "contested{$round}@example.com";

            $outcomes = $this->race('email', [$first->id, $second->id], $email);

            $this->assertSame(1, $outcomes->where('won', true)->count());
            $this->assertSame(1, $outcomes->where('invalid', true)->count());
            $this->assertSame(1, User::where('email', $email)->count());
        }
    }
}
