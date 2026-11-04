<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An allocation gets its period when its days are deducted, which for a period that
     * does not exist yet is the moment the period opens. Until then period_id stays empty.
     */
    public function up(): void
    {
        foreach (['leave_requests', 'overtime_requests'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('decision_note', 1000)->nullable()->after('rejection_reason');
            });
        }

        Schema::table('leave_request_allocations', function (Blueprint $table) {
            $table->foreignId('period_id')->nullable()->after('work_date')->constrained('leave_balance_periods')->restrictOnDelete();
        });

        Schema::table('leave_balance_entries', function (Blueprint $table) {
            $table->foreignId('leave_request_id')->nullable()->after('actor_id')->constrained()->restrictOnDelete();
        });

        Schema::create('request_locks', function (Blueprint $table) {
            $table->id();
            $table->string('request_kind', 20);
            $table->unsignedBigInteger('request_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');

            $table->unique(['request_kind', 'request_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_locks');

        Schema::table('leave_balance_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_request_id');
        });

        Schema::table('leave_request_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('period_id');
        });

        foreach (['leave_requests', 'overtime_requests'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('decision_note');
            });
        }
    }
};
