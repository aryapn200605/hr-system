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
    /**
     * The role name shown on screen. The stored name stays the enum value.
     */
    public function label(): string
    {
        return match ($this) {
            self::HrAdmin => __('HR Admin'),
            self::Approver => __('Approver'),
            self::Employee => __('Employee'),
        };
    }

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
            self::Approver, self::Employee => [PermissionName::DashboardView, PermissionName::RequestsSubmit],
        };
    }
}
