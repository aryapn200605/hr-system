<?php

use Database\Seeders\TranslationSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the Phase 14 texts (final decisions, automatic rejection on suspension). The seeder is safe to run again.
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
