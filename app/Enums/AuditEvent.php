<?php

namespace App\Enums;

enum AuditEvent: string
{
    case AdminBootstrapped = 'admin.bootstrapped';
    case UserCreated = 'user.created';
    case UserRoleChanged = 'user.role_changed';
    case UserUpdated = 'user.updated';
    case UserSuspended = 'user.suspended';
    case UserReactivated = 'user.reactivated';
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case TwoFactorEnrolled = 'two_factor.enrolled';
    case TwoFactorReconfigured = 'two_factor.reconfigured';
    case TwoFactorReset = 'two_factor.reset';
    case RecoveryCodeUsed = 'recovery_code.used';
    case RecoveryCodesRegenerated = 'recovery_codes.regenerated';
    case ReauthenticationSucceeded = 'reauthentication.succeeded';
    case ReauthenticationFailed = 'reauthentication.failed';
    case SessionTerminated = 'session.terminated';
    case InvitationCreated = 'invitation.created';
    case InvitationResent = 'invitation.resent';
    case InvitationConsumed = 'invitation.consumed';
    case InvitationDeliveryFailed = 'invitation.delivery_failed';
    case PasswordChanged = 'password.changed';
    case PasswordResetDeliveryFailed = 'password_reset.delivery_failed';
}
