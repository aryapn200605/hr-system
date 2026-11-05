<?php

use Database\Seeders\TranslationSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the Phase 13 texts (request emails and their failure notices). The seeder is safe to run again.
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
