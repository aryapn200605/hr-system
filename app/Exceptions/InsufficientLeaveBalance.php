<?php

namespace App\Exceptions;

use DomainException;

/**
 * A leave period cannot take the days of a request. The request stays pending.
 */
class InsufficientLeaveBalance extends DomainException
{
    public function __construct(public readonly string $period, public readonly int $needed, public readonly int $left)
    {
        parent::__construct(sprintf(
            'Not enough balance: this request needs %d working days from the leave period %s, which has %d left. It stays pending, and you can reject it with a reason.',
            $needed,
            $period,
            max($left, 0),
        ));
    }
}
