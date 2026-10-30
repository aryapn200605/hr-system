<?php

namespace App\Enums;

enum AuditEvent: string
{
    case AdminBootstrapped = 'admin.bootstrapped';
    case UserRoleChanged = 'user.role_changed';
    case UserSuspended = 'user.suspended';
}
