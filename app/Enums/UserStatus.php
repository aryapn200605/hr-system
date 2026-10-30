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
        return ucfirst(strtolower($this->value));
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
