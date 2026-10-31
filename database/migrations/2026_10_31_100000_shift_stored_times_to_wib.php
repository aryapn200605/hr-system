<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move stored times from UTC wall clock to WIB wall clock.
 *
 * These are MySQL TIMESTAMP columns, which follow the session time zone. The app used to write UTC
 * through a session on the server's own zone, so the shift is right only when that zone is +07:00:
 * on a UTC server the new connection setting alone moves them, and a shift would add 7 hours twice.
 * Epoch columns (sessions, cache, jobs) and users.two_factor_last_timestep are not times of day.
 */
return new class extends Migration
{
    private const HOURS = 7;

    private const WIB_OFFSET_MINUTES = 420;

    private const CONNECTION_ZONE = '+07:00';

    /**
     * @var array<string, list<string>>
     */
    public const COLUMNS = [
        'account_invitations' => ['expires_at', 'sent_at', 'accepted_at', 'revoked_at', 'created_at', 'updated_at'],
        'audit_logs' => ['created_at'],
        'failed_jobs' => ['failed_at'],
        'login_histories' => ['occurred_at'],
        'password_reset_tokens' => ['created_at'],
        'permissions' => ['created_at', 'updated_at'],
        'recovery_codes' => ['used_at', 'created_at', 'updated_at'],
        'roles' => ['created_at', 'updated_at'],
        'users' => ['two_factor_enrolled_at', 'last_login_at', 'created_at', 'updated_at'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->shift(self::HOURS);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->shift(-self::HOURS);
    }

    /**
     * Shift every listed column in one transaction, because a partial shift cannot be told apart later.
     * Raw statements on purpose: audit rows are immutable for the model, and this is the one exception.
     */
    private function shift(int $hours): void
    {
        $tables = array_filter(array_keys(self::COLUMNS), fn (string $table) => DB::table($table)->exists());

        if ($tables === []) {
            return;
        }

        $this->ensureShiftIsCorrectHere();

        DB::transaction(function () use ($tables, $hours) {
            foreach ($tables as $table) {
                $assignments = array_map(
                    fn (string $column) => sprintf('`%1$s` = `%1$s` + INTERVAL %2$d HOUR', $column, $hours),
                    self::COLUMNS[$table],
                );

                DB::statement(sprintf('UPDATE `%s` SET %s', $table, implode(', ', $assignments)));
            }
        });
    }

    /**
     * Refuse to touch data unless the server's own zone is +07:00 and this connection already uses WIB.
     */
    private function ensureShiftIsCorrectHere(): void
    {
        $zones = DB::selectOne(
            "SELECT @@session.time_zone AS connection_zone,
                TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', @@global.time_zone)) AS server_minutes"
        );

        if ($zones->connection_zone !== self::CONNECTION_ZONE || (int) $zones->server_minutes !== self::WIB_OFFSET_MINUTES) {
            throw new RuntimeException(sprintf(
                'Refusing to shift times: expected connection zone %s and a server on +07:00, got %s and %s minutes. Nothing changed.',
                self::CONNECTION_ZONE,
                $zones->connection_zone,
                $zones->server_minutes ?? 'unknown',
            ));
        }
    }
};
