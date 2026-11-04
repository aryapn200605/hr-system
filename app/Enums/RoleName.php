<?php

namespace App\Enums;

enum RoleName: string
{
    case HrAdmin = 'HR Admin';
    case Approver = 'Approver';
    case Employee = 'Employee';

    /**
     * Get the badge color of this role.
     */
    public function tone(): string
    {
        return match ($this) {
            self::HrAdmin => 'blue',
            self::Approver => 'purple',
            self::Employee => 'green',
        };
    }

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
