<?php

namespace App\Services\Reports;

use App\Enums\RequestStatus;
use Carbon\CarbonImmutable;

/**
 * One request as history and its export show it: a flat row of RequestHistory's query, without
 * the reason, the decision note or the evidence.
 */
final class HistoryRow
{
    private function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly int $userId,
        public readonly string $requester,
        public readonly ?string $department,
        public readonly ?string $categoryName,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        /** Working days of a leave request, hours of an overtime request. */
        public readonly int $amount,
        public readonly RequestStatus $status,
        public readonly CarbonImmutable $submittedAt,
        public readonly ?int $decidedBy,
        public readonly ?string $decider,
        public readonly ?CarbonImmutable $decidedAt,
    ) {}

    public static function fromRecord(object $record): self
    {
        return new self(
            type: $record->type,
            id: (int) $record->id,
            userId: (int) $record->user_id,
            requester: $record->requester,
            department: $record->department,
            categoryName: $record->category_name,
            start: CarbonImmutable::parse($record->starts),
            end: CarbonImmutable::parse($record->ends),
            amount: (int) $record->amount,
            status: RequestStatus::from($record->status),
            submittedAt: CarbonImmutable::parse($record->created_at),
            decidedBy: $record->decided_by === null ? null : (int) $record->decided_by,
            // A system decision (on suspension) has no decider row; it shows as "System".
            decider: ($record->system_reason ?? null) !== null ? __('System') : $record->decider,
            decidedAt: $record->decided_at === null ? null : CarbonImmutable::parse($record->decided_at),
        );
    }

    public function isLeave(): bool
    {
        return $this->type === 'leave';
    }

    /**
     * The category of a leave request, or "Overtime", in the user's language.
     */
    public function kindLabel(): string
    {
        return $this->isLeave() ? __($this->categoryName) : __('Overtime');
    }
}
