<?php

namespace App\Enums;

/**
 * The life of a leave or overtime request. Only pending requests can be cancelled (by the requester)
 * or decided (Phase 10). Rejected and cancelled requests no longer block dates or balance.
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * The statuses that hold dates and balance: a new request may not overlap them.
     *
     * @return list<string>
     */
    public static function blocking(): array
    {
        return [self::Pending->value, self::Approved->value];
    }

    /**
     * The name shown on screen. "Pending approval", because "Pending" is the account status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending approval'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Cancelled => 'gray',
        };
    }
}
