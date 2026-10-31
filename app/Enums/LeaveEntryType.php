<?php

namespace App\Enums;

enum LeaveEntryType: string
{
    case Opened = 'opened';
    case Closed = 'closed';
    case Adjusted = 'adjusted';
    case AnchorChanged = 'anchor_changed';

    /**
     * Get the label shown to users.
     */
    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Period opened',
            self::Closed => 'Period closed',
            self::Adjusted => 'Adjustment',
            self::AnchorChanged => 'Reset date changed',
        };
    }
}
