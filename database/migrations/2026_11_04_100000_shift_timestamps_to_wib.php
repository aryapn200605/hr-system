<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move every stored time from UTC wall-clock to WIB wall-clock.
 *
 * Until Phase 6 the application wrote UTC strings over a connection in the server's zone
 * (+07:00), so MySQL kept them as written. With the application and the connection now in
 * WIB, the same values must move forward seven hours to keep meaning the same instant.
 *
 * Epoch columns are left alone: sessions.last_activity, cache and job expirations, and
 * users.two_factor_last_timestep (TOTP timesteps are counted from the Unix epoch).
 */
return new class extends Migration
{
    /**
     * Every TIMESTAMP and DATETIME column that existed before the switch to WIB.
     * Taken from information_schema, not from memory. A test compares it with the schema.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNS = [
        'account_invitations' => ['accepted_at', 'created_at', 'expires_at', 'revoked_at', 'sent_at', 'updated_at'],
        'audit_logs' => ['created_at'],
        'failed_jobs' => ['failed_at'],
        'login_histories' => ['occurred_at'],
        'password_reset_tokens' => ['created_at'],
        'permissions' => ['created_at', 'updated_at'],
        'recovery_codes' => ['created_at', 'updated_at', 'used_at'],
        'roles' => ['created_at', 'updated_at'],
        'users' => ['created_at', 'last_login_at', 'two_factor_enrolled_at', 'updated_at'],
    ];

    /**
     * The shift, in hours.
     */
    private const HOURS = 7;

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
     * Move all listed columns by the given number of hours in one transaction.
     */
    private function shift(int $hours): void
    {
        $this->ensureWib();

        DB::transaction(function () use ($hours) {
            foreach (self::COLUMNS as $table => $columns) {
                // DATE_ADD keeps NULL as NULL, so empty columns need no filter.
                foreach ($columns as $column) {
                    DB::table($table)->update([$column => DB::raw('DATE_ADD(`'.$column.'`, INTERVAL '.$hours.' HOUR)')]);
                }
            }
        });
    }

    /**
     * The shift is only correct when the application, the connection and the MySQL server
     * are all at +07:00, because TIMESTAMP values are read and written in the session zone.
     */
    private function ensureWib(): void
    {
        if (config('app.timezone') !== 'Asia/Jakarta') {
            throw new RuntimeException('Refusing to shift times: the application timezone is not Asia/Jakarta.');
        }

        $session = DB::selectOne('SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS offset, @@session.time_zone AS zone');

        if ($session->offset !== '07:00:00') {
            throw new RuntimeException('Refusing to shift times: the database connection is not at +07:00.');
        }

        // Read the server's own zone by switching this session to it for one query.
        DB::statement('SET time_zone = @@global.time_zone');

        try {
            $server = DB::selectOne('SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS offset');
        } finally {
            DB::statement('SET time_zone = ?', [$session->zone]);
        }

        if ($server->offset !== '07:00:00') {
            throw new RuntimeException('Refusing to shift times: the MySQL server is not at +07:00.');
        }
    }
};
