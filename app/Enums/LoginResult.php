<?php

namespace App\Enums;

enum LoginResult: string
{
    case Success = 'success';
    case FailedCredentials = 'failed_credentials';
    case FailedAccountStatus = 'failed_account_status';
    case FailedTotp = 'failed_totp';
    case FailedRecovery = 'failed_recovery';

    /**
     * Get the label shown to users.
     */
    public function label(): string
    {
        return match ($this) {
            self::Success => 'Successful',
            self::FailedCredentials => 'Wrong password',
            self::FailedAccountStatus => 'Account not active',
            self::FailedTotp => 'Wrong authenticator code',
            self::FailedRecovery => 'Wrong recovery code',
        };
    }

    /**
     * Get the badge color of this result.
     */
    public function tone(): string
    {
        return $this === self::Success ? 'green' : 'red';
    }
}
