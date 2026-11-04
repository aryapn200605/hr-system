<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\TwoFactor;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class WibTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_04_100000_shift_timestamps_to_wib.php';

    /**
     * TIMESTAMP and DATETIME columns added after the switch to WIB. They hold WIB already,
     * so the shift migration does not list them. A new column goes here or in the migration.
     *
     * @var array<string, list<string>>
     */
    private const ADDED_AFTER_WIB = [
        'languages' => ['created_at', 'updated_at'],
        'translations' => ['created_at', 'updated_at'],
        'departments' => ['created_at', 'updated_at'],
        'supervisor_user' => ['created_at'],
        'leave_periods' => ['created_at', 'updated_at'],
        'leave_balance_entries' => ['created_at'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function raw(string $table, string $column, int $id): ?string
    {
        return DB::table($table)->where('id', $id)->value(DB::raw('CAST(`'.$column.'` AS CHAR)'));
    }

    public function test_the_application_and_the_connection_run_in_wib(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', now()->getTimezone()->getName());
        $this->assertSame('07:00:00', DB::selectOne('SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS o')->o);

        // Set explicitly, not inherited from the server's zone, so it holds on any MySQL host.
        $this->assertSame('+07:00', DB::selectOne('SELECT @@session.time_zone AS z')->z);
    }

    public function test_the_application_and_mysql_agree_on_the_instant_written(): void
    {
        $user = User::factory()->create();

        $drift = DB::table('users')->where('id', $user->id)->value(DB::raw('ABS(TIMESTAMPDIFF(SECOND, created_at, NOW()))'));

        $this->assertLessThanOrEqual(5, (int) $drift);
        $this->assertSame($user->created_at->getTimestamp(), $user->fresh()->created_at->getTimestamp());
    }

    public function test_every_time_column_in_the_schema_is_in_the_inventory(): void
    {
        $schema = [];

        foreach (DB::select(
            'SELECT table_name AS t, column_name AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND data_type IN ("timestamp", "datetime") ORDER BY 1, 2'
        ) as $row) {
            $schema[$row->t][] = $row->c;
        }

        $known = array_merge_recursive($this->migration()::COLUMNS, self::ADDED_AFTER_WIB);
        ksort($known);
        array_walk($known, fn (array &$columns) => sort($columns));

        $this->assertSame($known, $schema, 'A TIMESTAMP/DATETIME column is missing from the WIB inventory.');
    }

    public function test_epoch_columns_are_not_shifted_and_keep_integer_types(): void
    {
        $listed = $this->migration()::COLUMNS;

        foreach (['sessions' => 'last_activity', 'cache' => 'expiration', 'jobs' => 'available_at', 'users' => 'two_factor_last_timestep'] as $table => $column) {
            $this->assertNotContains($column, $listed[$table] ?? []);
            $type = DB::selectOne(
                'SELECT data_type AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column],
            )->d;
            $this->assertContains($type, ['int', 'bigint']);
        }
    }

    public function test_the_migration_moves_real_rows_forward_and_back_by_seven_hours(): void
    {
        $user = User::factory()->create(['two_factor_last_timestep' => 59_000_000]);
        DB::table('users')->where('id', $user->id)->update([
            'created_at' => '2026-11-03 20:30:00',
            'last_login_at' => '2026-11-04 05:10:00',
            'two_factor_enrolled_at' => null,
        ]);
        $login = LoginHistory::create([
            'user_id' => $user->id, 'result' => 'success',
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'occurred_at' => '2026-11-04 05:10:00',
        ]);
        DB::table('sessions')->insert(['id' => 'wib-check', 'user_id' => $user->id, 'payload' => '', 'last_activity' => 1_793_000_000]);

        $this->migration()->up();

        $this->assertSame('2026-11-04 03:30:00', $this->raw('users', 'created_at', $user->id), 'crosses midnight');
        $this->assertSame('2026-11-04 12:10:00', $this->raw('users', 'last_login_at', $user->id));
        $this->assertNull($this->raw('users', 'two_factor_enrolled_at', $user->id));
        $this->assertSame('2026-11-04 12:10:00', $this->raw('login_histories', 'occurred_at', $login->id));
        $this->assertSame(59_000_000, (int) DB::table('users')->where('id', $user->id)->value('two_factor_last_timestep'));
        $this->assertSame(1_793_000_000, (int) DB::table('sessions')->where('id', 'wib-check')->value('last_activity'));

        $this->migration()->down();

        $this->assertSame('2026-11-03 20:30:00', $this->raw('users', 'created_at', $user->id));
        $this->assertSame('2026-11-04 05:10:00', $this->raw('login_histories', 'occurred_at', $login->id));
    }

    public function test_a_failure_on_a_later_table_leaves_every_table_unshifted(): void
    {
        $user = User::factory()->create();
        $login = LoginHistory::create(['user_id' => $user->id, 'result' => 'success', 'occurred_at' => '2026-11-04 05:10:00']);

        // users is shifted last; seven more hours push this past the TIMESTAMP range, so MySQL refuses.
        DB::table('users')->where('id', $user->id)->update(['updated_at' => '2038-01-19 08:00:00']);

        try {
            $this->migration()->up();
            $this->fail('The shift should have hit the TIMESTAMP range.');
        } catch (QueryException) {
        }

        $this->assertSame('2026-11-04 05:10:00', $this->raw('login_histories', 'occurred_at', $login->id));
        $this->assertSame('2038-01-19 08:00:00', $this->raw('users', 'updated_at', $user->id));
    }

    public function test_the_migration_refuses_when_the_application_is_not_in_wib(): void
    {
        $user = User::factory()->create();
        $before = $this->raw('users', 'created_at', $user->id);
        config(['app.timezone' => 'UTC']);

        try {
            $this->migration()->up();
            $this->fail('The shift ran outside WIB.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('application timezone', $e->getMessage());
        }

        $this->assertSame($before, $this->raw('users', 'created_at', $user->id));
    }

    public function test_the_migration_refuses_when_the_connection_is_not_at_plus_seven(): void
    {
        $user = User::factory()->create();
        $before = $this->raw('users', 'created_at', $user->id);
        DB::statement("SET time_zone = '+00:00'");

        try {
            $this->migration()->up();
            $this->fail('The shift ran on a UTC connection.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connection', $e->getMessage());
        } finally {
            DB::statement("SET time_zone = '+07:00'");
        }

        $this->assertSame($before, $this->raw('users', 'created_at', $user->id));
    }

    public function test_the_server_zone_check_restores_the_session_zone(): void
    {
        $this->migration()->up();

        $this->assertSame('+07:00', DB::selectOne('SELECT @@session.time_zone AS z')->z);
    }

    public function test_dates_are_labelled_wib(): void
    {
        $html = Blade::render('<x-ui.datetime :value="$v" />', ['v' => CarbonImmutable::parse('2026-11-04 12:10:00')]);

        $this->assertStringContainsString('4 Nov 2026, 12:10 WIB', $html);
        $this->assertStringContainsString('datetime="2026-11-04T12:10:00+07:00"', $html);
        $this->assertStringNotContainsString('UTC', $html);
    }

    /**
     * RFC 6238 appendix B, SHA-1, last six digits. TOTP counts from the Unix epoch, so the zone must not matter.
     */
    public function test_totp_matches_the_rfc_6238_vectors_in_wib(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->travelTo(Carbon::createFromTimestamp($time, 'Asia/Jakarta'));

            $this->assertSame(intdiv($time, 30), TwoFactor::matchingTimestep($secret, $code), "vector at $time");
            $this->assertNull(TwoFactor::matchingTimestep($secret, $code, intdiv($time, 30)), "replay at $time");
        }
    }

    public function test_an_invitation_expires_at_the_configured_minute_in_wib(): void
    {
        $admin = User::factory()->twoFactorEnrolled()->create();
        $user = User::factory()->pending()->create();
        [$invitation, $token] = AccountInvitation::issueFor($user, $admin);

        $this->assertSame(
            now()->addMinutes(config('auth.invitations.expire'))->getTimestamp(),
            $invitation->fresh()->expires_at->getTimestamp(),
        );

        $this->travel(config('auth.invitations.expire') - 1)->minutes();
        $this->assertNotNull(AccountInvitation::findOpenByToken($token));

        $this->travel(2)->minutes();
        $this->assertNull(AccountInvitation::findOpenByToken($token));
    }

    public function test_a_reset_link_expires_at_the_configured_minute_in_wib(): void
    {
        $user = User::factory()->twoFactorEnrolled()->create();
        $broker = Password::broker();
        $token = $broker->createToken($user);

        $this->travel(config('auth.passwords.users.expire') - 1)->minutes();
        $this->assertTrue($broker->tokenExists($user, $token));

        $this->travel(2)->minutes();
        $this->assertFalse($broker->tokenExists($user, $token));
    }
}
