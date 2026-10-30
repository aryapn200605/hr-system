<?php

namespace App\Enums;

enum RoleName: string
{
    case HrAdmin = 'HR Admin';
    case Approver = 'Approver';
    case Employee = 'Employee';

    /**
     * Get the permissions granted to this role.
     *
     * @return list<PermissionName>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::HrAdmin => PermissionName::cases(),
            self::Approver, self::Employee => [PermissionName::DashboardView],
        };
    }
}
