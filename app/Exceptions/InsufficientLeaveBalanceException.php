<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * An approval found a leave period without enough days left. The request stays pending.
 */
class InsufficientLeaveBalanceException extends RuntimeException
{
    public function __construct(
        public readonly CarbonImmutable $periodStartsOn,
        public readonly CarbonImmutable $periodEndsOn,
        public readonly int $needed,
        public readonly int $remaining,
    ) {
        parent::__construct(__('Not enough leave balance in the period :start – :end: this request needs :days working days, :remaining remain. The request stays pending.', [
            'start' => $periodStartsOn->translatedFormat('j M Y'),
            'end' => $periodEndsOn->translatedFormat('j M Y'),
            'days' => $needed,
            'remaining' => $remaining,
        ]));
    }
}
