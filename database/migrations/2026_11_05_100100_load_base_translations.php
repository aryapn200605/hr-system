<?php

use Database\Seeders\TranslationSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Load the base texts, so every environment (including the test database) has them after migrating.
 * A later phase that adds texts adds a migration like this one: the seeder only inserts missing rows.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        (new TranslationSeeder)->run();
    }

    /**
     * Reverse the migrations. The rows go with the table when the previous migration is reversed,
     * and texts changed since then must not be lost by reversing this one alone.
     */
    public function down(): void {}
};
