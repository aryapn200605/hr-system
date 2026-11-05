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
            // Away from the primary blue and from the status colours (green, amber, red). Phase 15a proposal.
            self::HrAdmin => 'purple',
            self::Approver => 'teal',
            self::Employee => 'gray',
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
            self::Approver => [PermissionName::DashboardView, PermissionName::RequestsSubmit, PermissionName::RequestsDecide],
            self::Employee => [PermissionName::DashboardView, PermissionName::RequestsSubmit],
        };
    }
}
