<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:reset-two-factor {username}')]
#[Description('Remove a user\'s 2FA so that their next login forces a new enrollment')]
class ResetTwoFactor extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::query()->where('username', $this->argument('username'))->first();

        if (! $user) {
            $this->components->error('No user has that username.');

            return self::FAILURE;
        }

        if (! $user->hasCompletedTwoFactorEnrollment()) {
            $this->components->error("{$user->username} has no 2FA to reset.");

            return self::FAILURE;
        }

        $this->components->warn("This removes the authenticator and recovery codes of {$user->username} and ends all their sessions.");
        $this->components->warn('Until they enroll again, their password alone reaches the enrollment page. Confirm who is asking first.');

        if (! $this->confirm("Reset 2FA for {$user->username}?")) {
            $this->components->info('Cancelled. Nothing changed.');

            return self::FAILURE;
        }

        if (! $user->resetTwoFactor($this->operator())) {
            $this->components->error("{$user->username} has no 2FA to reset.");

            return self::FAILURE;
        }

        $this->components->info("2FA of {$user->username} was reset. Enrollment is required at next login.");

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
