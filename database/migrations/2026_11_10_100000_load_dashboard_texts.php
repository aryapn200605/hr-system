<?php

use Database\Seeders\TranslationSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the Phase 12 texts (dashboard and the read-only request page). The seeder is safe to run again.
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
