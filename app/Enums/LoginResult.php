<?php

namespace App\Enums;

enum LoginResult: string
{
    case Success = 'success';
    case FailedCredentials = 'failed_credentials';
    case FailedAccountStatus = 'failed_account_status';
    case FailedTotp = 'failed_totp';
    case FailedRecovery = 'failed_recovery';
}
