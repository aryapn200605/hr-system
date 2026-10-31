<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

class WibMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_31_100000_shift_stored_times_to_wib.php';

    /**
     * Tables created after the shift, which only ever held WIB.
     */
    private const TABLES_AFTER_THE_SHIFT = [
        'departments',
        'employee_supervisor',
        'leave_balance_entries',
        'leave_balance_periods',
    ];

    private const UTC_WALL_CLOCK = '2026-10-30 20:15:00';

    private const WIB_WALL_CLOCK = '2026-10-31 03:15:00';

    private const EPOCH = 1_793_000_000;

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /**
     * One row in every table the migration shifts, with every listed column set, plus the epoch tables.
     */
    private function seedOldRows(): void
    {
        $at = self::UTC_WALL_CLOCK;

        $user = DB::table('users')->insertGetId([
            'name' => 'Old', 'username' => 'old', 'email' => 'old@example.com', 'status' => 'ACTIVE',
            'leave_anchor_date' => '2026-10-30', 'two_factor_last_timestep' => 59_000_000,
            'two_factor_enrolled_at' => $at, 'last_login_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);

        DB::table('account_invitations')->insert([
            'user_id' => $user, 'token_hash' => str_repeat('a', 64), 'expires_at' => $at, 'sent_at' => $at,
            'accepted_at' => $at, 'revoked_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('audit_logs')->insert(['event' => AuditEvent::UserCreated->value, 'created_at' => $at]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'u', 'connection' => 'c', 'queue' => 'q', 'payload' => 'p', 'exception' => 'e', 'failed_at' => $at,
        ]);
        DB::table('login_histories')->insert(['user_id' => $user, 'result' => 'success', 'occurred_at' => $at]);
        DB::table('password_reset_tokens')->insert(['email' => 'old@example.com', 'token' => 'x', 'created_at' => $at]);
        DB::table('permissions')->insert(['name' => 'p', 'guard_name' => 'web', 'created_at' => $at, 'updated_at' => $at]);
        DB::table('recovery_codes')->insert([
            'user_id' => $user, 'code_hash' => str_repeat('b', 64), 'used_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('roles')->insert(['name' => 'r', 'guard_name' => 'web', 'created_at' => $at, 'updated_at' => $at]);

        DB::table('sessions')->insert(['id' => 's', 'user_id' => $user, 'payload' => '', 'last_activity' => self::EPOCH]);
        DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => self::EPOCH]);
        DB::table('cache_locks')->insert(['key' => 'k', 'owner' => 'o', 'expiration' => self::EPOCH]);
        DB::table('jobs')->insert([
            'queue' => 'q', 'payload' => 'p', 'attempts' => 0, 'reserved_at' => self::EPOCH,
            'available_at' => self::EPOCH, 'created_at' => self::EPOCH,
        ]);
    }

    private function assertEveryColumnReads(string $expected): void
    {
        foreach ($this->migration()::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame($expected, DB::table($table)->value($column), "{$table}.{$column}");
            }
        }
    }

    public function test_up_and_down_move_every_listed_column_and_restore_it_exactly(): void
    {
        $this->seedOldRows();
        $migration = $this->migration();

        $migration->up();
        $this->assertEveryColumnReads(self::WIB_WALL_CLOCK);

        $migration->down();
        $this->assertEveryColumnReads(self::UTC_WALL_CLOCK);
    }

    public function test_null_values_stay_null(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        $this->migration()->up();

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('last_login_at'));
    }

    public function test_epoch_columns_and_the_totp_timestep_are_not_touched(): void
    {
        $this->seedOldRows();

        $this->migration()->up();

        $this->assertSame(self::EPOCH, DB::table('sessions')->value('last_activity'));
        $this->assertSame(self::EPOCH, DB::table('cache')->value('expiration'));
        $this->assertSame(self::EPOCH, DB::table('cache_locks')->value('expiration'));
        $this->assertSame(self::EPOCH, DB::table('jobs')->value('available_at'));
        $this->assertSame(self::EPOCH, DB::table('jobs')->value('reserved_at'));
        $this->assertSame(self::EPOCH, DB::table('jobs')->value('created_at'));
        $this->assertSame(59_000_000, DB::table('users')->value('two_factor_last_timestep'));
    }

    public function test_no_stored_time_column_escapes_the_list(): void
    {
        $found = collect(DB::select(
            "SELECT table_name AS t, column_name AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND data_type IN ('timestamp', 'datetime')",
        ))
            ->reject(fn (object $row) => in_array($row->t, self::TABLES_AFTER_THE_SHIFT, true))
            ->map(fn (object $row) => $row->t.'.'.$row->c)
            ->sort()->values()->all();

        $listed = collect($this->migration()::COLUMNS)
            ->flatMap(fn (array $columns, string $table) => array_map(fn (string $column) => $table.'.'.$column, $columns))
            ->sort()->values()->all();

        $this->assertSame($listed, $found);
    }

    public function test_it_refuses_to_shift_data_on_a_connection_that_is_not_wib(): void
    {
        $this->seedOldRows();

        DB::statement("SET time_zone = '+00:00'");

        try {
            $this->migration()->up();
            $this->fail('The migration shifted data on a UTC connection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Nothing changed', $exception->getMessage());
        } finally {
            DB::statement("SET time_zone = '+07:00'");
        }

        $this->assertEveryColumnReads(self::UTC_WALL_CLOCK);
    }

    public function test_an_empty_database_needs_no_check_at_all(): void
    {
        DB::statement("SET time_zone = '+00:00'");

        try {
            $this->migration()->up();
            $this->addToAssertionCount(1);
        } finally {
            DB::statement("SET time_zone = '+07:00'");
        }
    }

    public function test_the_application_and_the_connection_both_run_on_wib(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', date_default_timezone_get());
        $this->assertSame('+07:00', DB::selectOne('SELECT @@session.time_zone AS zone')->zone);

        $this->freezeTime();

        $user = User::factory()->create();

        $this->assertSame(
            now()->format('Y-m-d H:i:s'),
            DB::selectOne('SELECT created_at FROM users WHERE id = ?', [$user->id])->created_at,
        );
    }

    public function test_a_stored_time_reads_back_as_the_same_instant(): void
    {
        $this->freezeTime();

        $entry = AuditLog::record(AuditEvent::UserCreated);

        $this->assertSame(now()->getTimestamp(), $entry->fresh()->created_at->getTimestamp());
    }

    public function test_totp_codes_follow_the_epoch_and_not_the_zone(): void
    {
        // RFC 6238 appendix B: the SHA-1 row for 1111111109 seconds, cut to six digits.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->travelTo(now()->setTimestamp(1_111_111_109));
        $this->assertSame(37_037_036, TwoFactor::matchingTimestep($secret, '081804'));

        date_default_timezone_set('UTC');

        try {
            $this->assertSame(37_037_036, TwoFactor::matchingTimestep($secret, '081804'));
        } finally {
            date_default_timezone_set('Asia/Jakarta');
        }
    }

    public function test_replay_protection_still_refuses_a_spent_code_on_wib(): void
    {
        $this->freezeTime();

        $user = User::factory()->twoFactorEnrolled()->create();
        $code = (new Google2FA)->oathTotp($user->two_factor_secret, (int) floor(now()->getTimestamp() / 30));

        $this->assertTrue(TwoFactor::verify($user, $code));
        $this->assertFalse(TwoFactor::verify($user->fresh(), $code));
    }
}
