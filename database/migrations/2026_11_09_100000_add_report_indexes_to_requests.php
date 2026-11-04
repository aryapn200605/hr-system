<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for history and recap across all users: the department recap reads requests by status
     * and start, and history lists everyone's requests newest first.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index(['status', 'starts_on'], 'leave_requests_status_starts_index');
            $table->index('created_at', 'leave_requests_created_index');
        });

        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->index(['status', 'start_at'], 'overtime_requests_status_start_index');
            $table->index('created_at', 'overtime_requests_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('leave_requests_status_starts_index');
            $table->dropIndex('leave_requests_created_index');
        });

        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropIndex('overtime_requests_status_start_index');
            $table->dropIndex('overtime_requests_created_index');
        });
    }
};
