<?php

namespace App\Enums;

enum AuditEvent: string
{
    case AdminBootstrapped = 'admin.bootstrapped';
    case UserCreated = 'user.created';
    case UserRoleChanged = 'user.role_changed';
    case UserSuspended = 'user.suspended';
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case TwoFactorEnrolled = 'two_factor.enrolled';
    case RecoveryCodeUsed = 'recovery_code.used';
    case InvitationCreated = 'invitation.created';
    case InvitationResent = 'invitation.resent';
    case InvitationConsumed = 'invitation.consumed';
    case InvitationDeliveryFailed = 'invitation.delivery_failed';
    case PasswordChanged = 'password.changed';
    case PasswordResetDeliveryFailed = 'password_reset.delivery_failed';
}
