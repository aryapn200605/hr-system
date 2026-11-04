<?php

namespace App\Services\Requests;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use Carbon\CarbonImmutable;

/**
 * A live soft lock: who holds it and until when.
 */
final class ReviewLockHolder
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly CarbonImmutable $until,
    ) {}

    /**
     * The live lock in a request row, or null when it has none, it expired, or the request is no longer pending.
     */
    public static function fromRow(object $row): ?self
    {
        if ($row->status !== RequestStatus::Pending->value || $row->review_locked_by === null || $row->review_locked_until === null) {
            return null;
        }

        $until = CarbonImmutable::parse($row->review_locked_until);

        return $until->isFuture() ? new self((int) $row->review_locked_by, (string) $row->name, $until) : null;
    }

    /**
     * The live lock of a request model, or null.
     */
    public static function of(LeaveRequest|OvertimeRequest $request): ?self
    {
        $holder = $request->review_locked_by === null ? null : $request->reviewLocker;

        return self::fromRow((object) [
            'status' => $request->status->value,
            'review_locked_by' => $request->review_locked_by,
            'review_locked_until' => $request->review_locked_until?->toDateTimeString(),
            'name' => $holder?->name ?? '',
        ]);
    }

    public function isHeldBy(int $userId): bool
    {
        return $this->userId === $userId;
    }
}
