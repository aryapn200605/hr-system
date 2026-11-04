<?php

namespace App\Services\Requests;

use Carbon\CarbonImmutable;

/**
 * A leave period as a request sees it: a real row, or the next period projected from the anchor date
 * with the full entitlement because the daily job has not opened it yet (id null).
 */
final class LeavePeriodSlot
{
    public function __construct(
        public readonly ?int $id,
        public readonly CarbonImmutable $startsOn,
        public readonly CarbonImmutable $endsOn,
        public readonly int $entitlement,
        public readonly int $used,
        public readonly int $pending,
    ) {}

    public function isProjected(): bool
    {
        return $this->id === null;
    }

    /**
     * Entitlement minus used minus what pending requests have allocated here. Never below zero.
     */
    public function available(): int
    {
        return max(0, $this->entitlement - $this->used - $this->pending);
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($this->startsOn, $this->endsOn);
    }
}
