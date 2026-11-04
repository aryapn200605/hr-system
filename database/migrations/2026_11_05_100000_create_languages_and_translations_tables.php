<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 64);
            $table->string('native_name', 64);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10);
            // '*' holds whole-sentence keys, as Laravel's JSON files do. Other groups hold the framework's
            // keyed messages (validation, auth, passwords, pagination) with dotted keys for nested arrays.
            $table->string('group', 64)->default('*');
            // Binary collation: keys that differ only in case or accents are different keys.
            $table->string('key', 500)->collation('utf8mb4_bin');
            $table->text('value');
            $table->timestamps();

            $table->unique(['locale', 'group', 'key']);
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete()->cascadeOnUpdate();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('status');
            $table->foreign('locale')->references('code')->on('languages')->nullOnDelete()->cascadeOnUpdate();
        });

        $now = now();
        DB::table('languages')->insert([
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_active' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'id', 'name' => 'Indonesian', 'native_name' => 'Bahasa Indonesia', 'is_active' => true, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['locale']);
            $table->dropColumn('locale');
        });

        Schema::dropIfExists('translations');
        Schema::dropIfExists('languages');
    }
};
