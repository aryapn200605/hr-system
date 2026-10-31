<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A leave period as a request sees it: a real row, or the next one projected from the reset date.
 */
final readonly class LeavePeriodWindow
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $entitlement,
        public int $used,
        public bool $projected,
    ) {}

    public function covers(string $date): bool
    {
        return $date >= $this->start->toDateString() && $date <= $this->end->toDateString();
    }

    public function label(): string
    {
        return $this->start->format('j M Y').' to '.$this->end->format('j M Y');
    }
}
