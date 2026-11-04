<?php

namespace App\Console\Commands;

use App\Services\LeaveBalance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The daily leave reset: open each user's new period on the anniversary of their anchor date, and
 * any period missed while the server was off. Safe to run any number of times.
 */
#[Signature('app:leave-periods-sync')]
#[Description('Open the leave periods that should have started by today, for every user')]
class SyncLeavePeriods extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LeaveBalance $balance): int
    {
        $this->components->info("Checked {$balance->syncAll()} users.");

        return self::SUCCESS;
    }
}
