<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decisions made by the system, not by a person (Phase 14, DECISIONS.md section 11): the code of the
     * fixed reason, translated when shown. Null for every decision a person made.
     */
    public function up(): void
    {
        foreach (['leave_requests', 'overtime_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('system_reason', 40)->nullable()->after('decision_note');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['leave_requests', 'overtime_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('system_reason');
            });
        }
    }
};
