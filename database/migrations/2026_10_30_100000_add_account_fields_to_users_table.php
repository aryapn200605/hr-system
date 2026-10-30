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
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->unique()->after('name');
            $table->string('password')->nullable()->change();
            $table->string('status', 20)->default('PENDING')->index()->after('password');
            $table->text('two_factor_secret')->nullable()->after('status');
            $table->timestamp('two_factor_enrolled_at')->nullable()->after('two_factor_secret');
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable()->after('two_factor_enrolled_at');
            $table->timestamp('last_login_at')->nullable()->after('two_factor_last_timestep');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username',
                'status',
                'two_factor_secret',
                'two_factor_enrolled_at',
                'two_factor_last_timestep',
                'last_login_at',
            ]);
            $table->string('password')->nullable(false)->change();
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }
};
