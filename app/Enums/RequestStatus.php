<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Get the label shown to users.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get the badge color of this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Cancelled => 'gray',
        };
    }

    /**
     * The statuses that keep hold of their dates. Rejected and cancelled requests free them.
     *
     * @return list<self>
     */
    public static function holding(): array
    {
        return [self::Pending, self::Approved];
    }
}
