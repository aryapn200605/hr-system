<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\TranslationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Real race: several processes load the base texts at once on committed rows, as two deploys or a
 * migration plus a manual seed could. No RefreshDatabase, whose transaction would hide the rows.
 */
class TranslationSeederConcurrencyTest extends TestCase
{
    private const SEEDERS = 4;

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->user) {
            DB::table('users')->where('id', $this->user->id)->delete();
        }

        // Leave the base texts complete for the other tests.
        (new TranslationSeeder)->run();

        parent::tearDown();
    }

    public function test_concurrent_seeding_inserts_each_text_once(): void
    {
        (new TranslationSeeder)->run();
        $expected = DB::table('translations')->count();
        DB::table('translations')->where('locale', 'id')->delete();

        $this->user = User::factory()->create();
        $startAt = microtime(true) + 4;

        $pool = Process::pool(function ($pool) use ($startAt) {
            foreach (range(1, self::SEEDERS) as $seeder) {
                $pool->path(base_path())->env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'hr_system_test'])
                    ->command([PHP_BINARY, 'tests/Support/race.php', 'seed-translations', (string) $this->user->id, '0', (string) $startAt]);
            }
        })->start()->wait();

        $outcomes = collect($pool->collect())->map(function ($result) {
            $this->assertTrue($result->successful(), $result->errorOutput());

            $outcome = json_decode($result->output(), true);
            $this->assertIsArray($outcome, $result->output());

            return $outcome;
        });

        $this->assertTrue($outcomes->every(fn (array $o) => $o['reached_barrier']), 'A competitor started late, so this run proved nothing.');
        $this->assertTrue($outcomes->every(fn (array $o) => $o['won']), 'Every run succeeds without a duplicate-key error.');
        $this->assertSame($expected, DB::table('translations')->count());
        $this->assertSame(0, DB::table('translations')->select('locale', 'group', 'key')->groupBy('locale', 'group', 'key')->havingRaw('COUNT(*) > 1')->count());
    }
}
