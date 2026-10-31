<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allocations hold one row per working day of a request that deducts the balance. Period
     * boundaries can move after a request is made, by a manual reset or a new reset date, so a
     * day's period is always read from its date. The rows also keep which days counted when the
     * request was made, which a later change to the holidays must not alter.
     */
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_category_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('working_days');
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_mime', 50)->nullable();
            $table->unsignedInteger('evidence_size')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'start_date']);
        });

        Schema::create('leave_request_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->restrictOnDelete();
            $table->date('work_date');

            $table->unique(['leave_request_id', 'work_date']);
            $table->index('work_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_request_allocations');
        Schema::dropIfExists('leave_requests');
    }
};
