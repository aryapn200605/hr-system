<?php

use Database\Seeders\TranslationSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the Phase 15 texts: the 24 of the layout and gallery (15a, which had no migration) and the form
     * components (15b). The seeder only inserts missing rows, so it is safe to run again.
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
