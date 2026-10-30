<?php

namespace App\Console\Commands;

use App\Models\AccountInvitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-invitations {--days=90 : Keep dead invitations for this many days after they stopped working}')]
#[Description('Delete invitations that were never used and stopped working long ago')]
class PruneInvitations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            $this->components->error('--days must be a whole number of at least 1.');

            return self::FAILURE;
        }

        $deleted = AccountInvitation::query()->prunable(now()->subDays($days))->delete();

        $this->components->info("Deleted {$deleted} dead invitations.");

        return self::SUCCESS;
    }
}
