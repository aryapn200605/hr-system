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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_category_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            // Frozen at submission: a holiday or category change later does not alter the request.
            $table->unsignedSmallInteger('working_days');
            $table->boolean('deducts_balance');
            $table->text('reason');
            $table->string('status', 20);
            $table->timestamp('cancelled_at')->nullable();
            $this->evidenceColumns($table);
            $table->timestamps();

            $table->index(['user_id', 'status', 'starts_on']);
        });

        // How the working days of a deducting request are split over leave periods. A period that does
        // not exist yet is projected from the anchor date, with a null leave_period_id until Phase 10.
        Schema::create('leave_request_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_period_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            // The part of the request inside that period.
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('days');
            $table->timestamps();

            // A short name: the generated one is over MySQL's 64-character limit.
            $table->unique(['leave_request_id', 'period_starts_on'], 'leave_allocations_request_period_unique');
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // WIB, whole hours. The end may be on the next day.
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedTinyInteger('hours');
            // The calendar dates the overtime touches: from the start to the last hour worked.
            $table->date('first_date');
            $table->date('last_date');
            $table->text('reason');
            $table->string('status', 20);
            $table->timestamp('cancelled_at')->nullable();
            $this->evidenceColumns($table);
            $table->timestamps();

            $table->index(['user_id', 'status', 'start_at']);
            $table->index(['user_id', 'status', 'first_date']);
        });
    }

    /**
     * Optional evidence on the private "evidence" disk: a random file name, the uploaded name for
     * downloads, and the type detected from the content.
     */
    private function evidenceColumns(Blueprint $table): void
    {
        $table->string('evidence_path', 100)->nullable();
        $table->string('evidence_name', 255)->nullable();
        $table->string('evidence_mime', 50)->nullable();
        $table->unsignedInteger('evidence_size')->nullable();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('leave_request_allocations');
        Schema::dropIfExists('leave_requests');
    }
};
