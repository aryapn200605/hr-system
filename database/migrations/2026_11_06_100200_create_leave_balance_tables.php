<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The anchor date comes from created_at, which the WIB shift migration (2026_11_04_100000) has
     * already moved to WIB, so an account created between 17:00 and 24:00 WIB keeps its own date.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('leave_anchor_date')->nullable()->after('join_date');
        });

        DB::table('users')->update(['leave_anchor_date' => DB::raw('DATE(COALESCE(created_at, NOW()))')]);

        Schema::table('users', function (Blueprint $table) {
            $table->date('leave_anchor_date')->nullable(false)->change();
        });

        Schema::create('leave_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            // Days granted for the period, including manual adjustments. Remaining is entitlement - used.
            $table->unsignedSmallInteger('entitlement');
            $table->unsignedSmallInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'starts_on']);
            $table->index(['user_id', 'ends_on']);
        });

        Schema::create('leave_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null means the system, such as the daily job.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->smallInteger('change');
            // Remaining days of the period before and after the entry.
            $table->smallInteger('before');
            $table->smallInteger('after');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balance_entries');
        Schema::dropIfExists('leave_periods');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('leave_anchor_date');
        });
    }
};
