<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove reset tokens and invitations that can no longer be used, once they are older than
 * the retention. Safe to run any number of times: each run only deletes what is past the cutoff.
 */
#[Signature('app:prune-expired-tokens')]
#[Description('Remove expired password reset tokens and finished invitations older than the retention')]
class PruneExpiredTokens extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = config('auth.prune.retention_days');

        if (! is_int($days) || $days < 1) {
            $this->components->error('auth.prune.retention_days must be a whole number of at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // A token expires a fixed number of minutes after it is created.
        $tokens = DB::table(config('auth.passwords.users.table'))
            ->where('created_at', '<', $cutoff->copy()->subMinutes(config('auth.passwords.users.expire')))
            ->delete();

        // An open invitation has no accepted or revoked time and expires in the future, so it never matches.
        $invitations = DB::table('account_invitations')
            ->where(fn ($query) => $query
                ->where('expires_at', '<', $cutoff)
                ->orWhere('accepted_at', '<', $cutoff)
                ->orWhere('revoked_at', '<', $cutoff))
            ->delete();

        $this->components->info("Removed {$tokens} reset tokens and {$invitations} invitations older than {$days} days.");

        return self::SUCCESS;
    }
}
