<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPENING_ENTITLEMENT = 12;

    /**
     * Run the migrations, and give every existing user their first period.
     */
    public function up(): void
    {
        Schema::create('leave_balance_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('entitlement');
            $table->unsignedSmallInteger('used')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'start_date']);
            $table->index(['closed_at', 'end_date']);
        });

        Schema::create('leave_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('leave_balance_periods')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->unsignedSmallInteger('entitlement_before')->nullable();
            $table->unsignedSmallInteger('entitlement_after');
            $table->unsignedSmallInteger('used_before')->nullable();
            $table->unsignedSmallInteger('used_after');
            $table->string('reason', 500);
            $table->timestamp('created_at')->nullable();

            $table->index(['period_id', 'id']);
        });

        DB::table('users')->orderBy('id')->each(function (object $user) {
            $periodId = DB::table('leave_balance_periods')->insertGetId([
                'user_id' => $user->id,
                'start_date' => $user->leave_anchor_date,
                'end_date' => DB::raw("DATE_SUB(DATE_ADD('{$user->leave_anchor_date}', INTERVAL 1 YEAR), INTERVAL 1 DAY)"),
                'entitlement' => self::OPENING_ENTITLEMENT,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('leave_balance_entries')->insert([
                'period_id' => $periodId,
                'type' => 'opened',
                'entitlement_after' => self::OPENING_ENTITLEMENT,
                'used_after' => 0,
                'reason' => 'Opening balance when leave balances were introduced.',
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balance_entries');
        Schema::dropIfExists('leave_balance_periods');
    }
};
