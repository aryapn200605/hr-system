<?php

use App\Services\LeaveBalance;
use Database\Seeders\TranslationSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the Phase 8 texts and open the current leave period of every existing user. The new
     * permissions come from RolePermissionSeeder, which the owner runs after migrating (README).
     * Both are idempotent: they only add what is missing.
     */
    public function up(): void
    {
        (new TranslationSeeder)->run();

        app(LeaveBalance::class)->syncAll();
    }

    /**
     * The rows are removed with the tables of the earlier migrations.
     */
    public function down(): void
    {
        //
    }
};
