<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * One leave period of one user in the balance recap: a real period, or the next one projected from
 * the anchor date because the daily job has not opened it yet.
 */
final class BalanceLine
{
    public function __construct(
        public readonly CarbonImmutable $startsOn,
        public readonly CarbonImmutable $endsOn,
        public readonly bool $projected,
        public readonly int $entitlement,
        public readonly int $used,
        /** Approved leave waiting to be deducted when its period opens. */
        public int $approvedNotDeducted = 0,
        /** Days held by pending requests. */
        public int $pending = 0,
    ) {}

    /**
     * Entitlement minus used minus approved leave not deducted yet. Pending days are shown apart.
     */
    public function remaining(): int
    {
        return $this->entitlement - $this->used - $this->approvedNotDeducted;
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($this->startsOn, $this->endsOn);
    }
}
