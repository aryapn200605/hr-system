<?php

namespace App\Enums;

enum UserStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';

    /**
     * Get the label shown to users.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }

    /**
     * Get the badge color of this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Suspended => 'red',
        };
    }
}
