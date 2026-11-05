<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decisions on requests (Phase 10): who decided and when, the approval note or rejection reason,
     * the soft review lock, and the link between leave balance and the approved request.
     */
    public function up(): void
    {
        foreach (['leave_requests', 'overtime_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignId('decided_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable()->after('decided_by');
                // The optional approval note, or the required rejection reason.
                $table->text('decision_note')->nullable()->after('decided_at');
                // The soft lock of DECISIONS.md section 6. Expiry is checked on access; no job clears it.
                $table->foreignId('review_locked_by')->nullable()->after('decision_note')->constrained('users')->nullOnDelete();
                $table->timestamp('review_locked_until')->nullable()->after('review_locked_by');

                // The inbox lists pending requests oldest first; "Decided by me" lists one decider's.
                $table->index(['status', 'created_at'], $name.'_status_created_index');
                $table->index(['decided_by', 'decided_at'], $name.'_decided_index');
            });
        }

        Schema::table('leave_request_allocations', function (Blueprint $table) {
            // Set when the part was deducted from a real period. An approved part of a period that has
            // not started yet stays null until LeaveBalance::sync() opens that period.
            $table->timestamp('charged_at')->nullable()->after('days');
        });

        Schema::table('leave_balance_entries', function (Blueprint $table) {
            $table->foreignId('leave_request_id')->nullable()->after('actor_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_balance_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_request_id');
        });

        Schema::table('leave_request_allocations', function (Blueprint $table) {
            $table->dropColumn('charged_at');
        });

        foreach (['leave_requests', 'overtime_requests'] as $name) {
            // The foreign keys first: MySQL uses the decided index for the decided_by key and refuses to drop
            // the index while the key exists (found by the Phase 14 rollback check).
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['decided_by']);
                $table->dropForeign(['review_locked_by']);
            });

            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_status_created_index');
                $table->dropIndex($name.'_decided_index');
                $table->dropColumn(['decided_by', 'review_locked_by', 'decided_at', 'decision_note', 'review_locked_until']);
            });
        }
    }
};
