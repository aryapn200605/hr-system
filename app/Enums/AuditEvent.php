<?php

namespace App\Enums;

enum AuditEvent: string
{
    case AdminBootstrapped = 'admin.bootstrapped';
    case UserRoleChanged = 'user.role_changed';
    case UserSuspended = 'user.suspended';
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case TwoFactorEnrolled = 'two_factor.enrolled';
    case RecoveryCodeUsed = 'recovery_code.used';
}
