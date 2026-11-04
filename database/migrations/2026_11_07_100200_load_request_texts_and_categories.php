<?php

use Database\Seeders\LeaveCategorySeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the Phase 9 texts and the leave categories. The new permissions come from RolePermissionSeeder,
     * which is run after migrating (README). Both seeders are safe to run again.
     */
    public function up(): void
    {
        (new TranslationSeeder)->run();
        (new LeaveCategorySeeder)->run();
    }

    /**
     * The rows go with the tables of the earlier migrations.
     */
    public function down(): void
    {
        //
    }
};
