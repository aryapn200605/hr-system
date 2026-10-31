<?php

namespace App\Console\Commands;

use App\Exceptions\AccountProtectionException;
use App\Models\User;
use App\Support\LeaveBalances;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:reset-leave-balances {username? : Only this user} {--now : Start a new period for this user today, even if their reset date is later}')]
#[Description('Close leave periods that have ended and open the next ones. Safe to run repeatedly.')]
class ResetLeaveBalances extends Command
{
    private const CHUNK = 200;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $username = $this->argument('username');

        if ($username === null) {
            if ($this->option('now')) {
                $this->components->error('--now needs a username.');

                return self::FAILURE;
            }

            return $this->checkEveryone();
        }

        $user = User::query()->where('username', $username)->first();

        if (! $user) {
            $this->components->error('No user has that username.');

            return self::FAILURE;
        }

        return $this->option('now') ? $this->resetNow($user) : $this->checkOne($user);
    }

    /**
     * Run every user, so one failure does not keep the others from their new period.
     */
    private function checkEveryone(): int
    {
        $opened = 0;
        $failed = 0;

        User::query()->select('id')->chunkById(self::CHUNK, function ($users) use (&$opened, &$failed) {
            foreach ($users as $user) {
                try {
                    $opened += LeaveBalances::rollOver($user, 'daily_check');
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            }
        });

        $this->components->info("{$opened} new periods opened.");

        if ($failed > 0) {
            $this->components->error("{$failed} users failed. Run the command again after fixing the cause.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function checkOne(User $user): int
    {
        $opened = LeaveBalances::rollOver($user, 'console', $this->operator());

        $this->components->info("{$opened} new periods opened for {$user->username}.");

        return self::SUCCESS;
    }

    private function resetNow(User $user): int
    {
        try {
            LeaveBalances::resetNow($user, null, 'console', $this->operator());
        } catch (AccountProtectionException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("A new period started today for {$user->username}.");

        return self::SUCCESS;
    }

    /**
     * Name the operating system account running this command, for the audit entry.
     * It comes from the environment, so it is a hint and not proof of who ran it.
     */
    private function operator(): string
    {
        $name = getenv('USERNAME') ?: getenv('USER') ?: getenv('LOGNAME') ?: 'unknown';

        return mb_substr($name, 0, 100).'@'.gethostname();
    }
}
