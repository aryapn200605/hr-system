<?php

namespace App\Console\Commands;

use App\Exceptions\AccountProtectionException;
use App\Models\User;
use App\Support\AccountRules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('app:create-admin')]
#[Description('Create the first HR Admin account')]
class CreateAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RolePermissionSeeder $seeder): int
    {
        $seeder->run();

        if (User::query()->activeHrAdmin()->exists()) {
            $this->components->error('An active HR Admin already exists.');

            return self::FAILURE;
        }

        $input = [
            'name' => trim((string) $this->ask('Full name')),
            'username' => trim((string) $this->ask('Username')),
            'email' => trim((string) $this->ask('Email')),
            'password' => (string) $this->secret('Password', false),
            'password_confirmation' => (string) $this->secret('Confirm password', false),
        ];

        $validator = Validator::make($input, [
            'name' => AccountRules::name(),
            'username' => AccountRules::username(),
            'email' => AccountRules::email(),
            'password' => AccountRules::password(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        try {
            $admin = User::createFirstHrAdmin($validator->safe()->only(['name', 'username', 'email', 'password']));
        } catch (AccountProtectionException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("HR Admin {$admin->username} created. 2FA enrollment is required at first login.");

        return self::SUCCESS;
    }
}
