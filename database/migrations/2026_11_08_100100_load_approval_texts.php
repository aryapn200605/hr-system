<?php

use Database\Seeders\TranslationSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the Phase 10 texts. The new permission comes from RolePermissionSeeder, which is run after
     * migrating (README). The seeder is safe to run again.
     */
    public function up(): void
    {
        (new TranslationSeeder)->run();
    }

    /**
     * The rows stay: the seeder never removes texts.
     */
    public function down(): void
    {
        //
    }
};
