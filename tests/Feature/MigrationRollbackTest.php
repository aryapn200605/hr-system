<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every migration can be rolled back and run again (Phase 14). The Phase 10 migration's down() once
 * dropped an index that a foreign key still needed, so a full rollback stopped halfway. Runs on
 * hr_system_test only and leaves it fully migrated, as the other tests expect.
 */
class MigrationRollbackTest extends TestCase
{
    public function test_every_migration_rolls_back_and_runs_again(): void
    {
        $this->assertSame('hr_system_test', DB::connection()->getDatabaseName());

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();

        $left = collect(DB::select('SHOW TABLES'))->map(fn ($row) => current((array) $row))->reject(fn ($name) => $name === 'migrations')->values()->all();
        $this->assertSame([], $left, 'Tables left after rolling back every migration.');

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('migrations')->count() - count(glob(database_path('migrations/*.php'))));
        $this->assertTrue(Schema::hasColumn('leave_requests', 'system_reason'));
        $this->artisan('app:translations-missing')->assertSuccessful();
    }
}
