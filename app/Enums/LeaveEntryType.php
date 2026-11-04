<?php

namespace App\Enums;

/**
 * Kinds of rows in the leave balance ledger.
 */
enum LeaveEntryType: string
{
    /** A period was opened with its entitlement. */
    case Opened = 'opened';

    /** HR Admin added or removed days, with a reason. */
    case Adjusted = 'adjusted';

    /** A manual reset ended the period early. The remaining days do not move: they lapse with the period. */
    case Closed = 'closed';

    /**
     * The name shown on screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::Opened => __('Period opened'),
            self::Adjusted => __('Adjusted'),
            self::Closed => __('Closed by reset'),
        };
    }
}
