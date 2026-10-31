<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Runs after the WIB shift, so the backfill is the date in WIB.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('status')->constrained()->restrictOnDelete();
            $table->date('joined_at')->nullable()->after('department_id');
            $table->date('leave_anchor_date')->nullable()->after('joined_at');
        });

        DB::table('users')->update(['leave_anchor_date' => DB::raw('COALESCE(DATE(created_at), CURRENT_DATE)')]);

        Schema::table('users', function (Blueprint $table) {
            $table->date('leave_anchor_date')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['joined_at', 'leave_anchor_date']);
        });
    }
};
