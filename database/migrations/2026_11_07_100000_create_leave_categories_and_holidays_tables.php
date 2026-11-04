<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Reference data kept by developers through LeaveCategorySeeder. There is no management screen.
        Schema::create('leave_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            // The English name; the screen shows its translation.
            $table->string('name', 100);
            $table->boolean('deducts_balance');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Public holidays and collective leave days, kept by HR Admin. Working days skip them.
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 100);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('leave_categories');
    }
};
