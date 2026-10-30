<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait SeedsSessions
{
    /**
     * Write a row to the sessions table, as another device of this user would have.
     */
    protected function seedSession(User $user, string $id, ?int $lastActivity = null, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => $userAgent,
            'payload' => 'unreadable',
            'last_activity' => $lastActivity ?? now()->getTimestamp(),
        ]);
    }
}
