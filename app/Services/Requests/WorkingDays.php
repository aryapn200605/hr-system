<?php

namespace App\Services\Requests;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one definition of a working day (DECISIONS.md section 4): Monday to Friday, minus the
 * public holidays and collective leave days in the holidays table.
 */
class WorkingDays
{
    /**
     * The number of working days from $from to $to, both included. Zero when $to is before $from.
     */
    public function count(CarbonInterface $from, CarbonInterface $to): int
    {
        $day = CarbonImmutable::instance($from)->startOfDay();
        $last = CarbonImmutable::instance($to)->startOfDay();

        if ($last->lt($day)) {
            return 0;
        }

        $holidays = Holiday::query()
            ->whereBetween('date', [$day->toDateString(), $last->toDateString()])
            ->pluck('date')
            ->mapWithKeys(fn (CarbonInterface $date) => [$date->toDateString() => true])
            ->all();

        $count = 0;

        for (; $day->lte($last); $day = $day->addDay()) {
            if ($day->isWeekday() && ! isset($holidays[$day->toDateString()])) {
                $count++;
            }
        }

        return $count;
    }
}
