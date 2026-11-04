<?php

namespace App\Exceptions;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use RuntimeException;

/**
 * A decision refused for a reason that is audited: no authority, or not enough leave balance.
 * App\Services\Requests\Decisions writes the audit row after the transaction has rolled back.
 */
class DecisionRefusedException extends RuntimeException
{
    public const NO_AUTHORITY = 'no_authority';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';

    public function __construct(
        public readonly string $reason,
        public readonly LeaveRequest|OvertimeRequest $record,
        string $message,
    ) {
        parent::__construct($message);
    }
}
