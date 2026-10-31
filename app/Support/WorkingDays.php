<?php

namespace App\Support;

use App\Models\PublicHoliday;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * The one place that decides what a working day is: Monday to Friday, minus public holidays.
 */
class WorkingDays
{
    /**
     * Get the working days in a range, both ends included.
     *
     * @return list<string> Dates as Y-m-d.
     */
    public static function between(CarbonInterface $start, CarbonInterface $end): array
    {
        $holidays = PublicHoliday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->toBase()
            ->pluck('date')
            ->flip();

        $days = [];

        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! $day->isWeekend() && ! $holidays->has($day->toDateString())) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }
}
