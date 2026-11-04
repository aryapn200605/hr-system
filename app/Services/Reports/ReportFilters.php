<?php

namespace App\Services\Reports;

use App\Enums\RequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The filters of history, recap and their exports, read from the page's properties or the export's
 * query string. Only plain strings in the expected shape are taken; anything else counts as "no filter",
 * so a forged value can only narrow a list, never widen what Visibility allows.
 */
final class ReportFilters
{
    public const KEYS = ['employee', 'department', 'type', 'category', 'status', 'from', 'to', 'periods', 'year'];

    public const TYPES = ['leave', 'overtime'];

    public const NO_DEPARTMENT = 'none';

    public const ALL_STATUSES = 'all';

    private function __construct(
        public readonly ?int $employee,
        /** A department ID, NO_DEPARTMENT, or null for every department. */
        public readonly int|string|null $department,
        public readonly ?string $type,
        public readonly ?string $category,
        public readonly ?RequestStatus $status,
        public readonly bool $allStatuses,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
        public readonly bool $allPeriods,
        public readonly int $year,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input): self
    {
        $text = fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        $department = $text('department');
        $from = self::date($text('from'));
        $to = self::date($text('to'));

        // A range typed the wrong way round is read the right way round.
        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $year = $text('year');

        return new self(
            employee: self::id($text('employee')),
            department: $department === self::NO_DEPARTMENT ? self::NO_DEPARTMENT : self::id($department),
            type: in_array($text('type'), self::TYPES, true) ? $text('type') : null,
            category: preg_match('/^[a-z0-9_-]{1,50}$/', $text('category')) ? $text('category') : null,
            status: RequestStatus::tryFrom($text('status')),
            allStatuses: $text('status') === self::ALL_STATUSES,
            from: $from,
            to: $to,
            allPeriods: $text('periods') === 'all',
            year: preg_match('/^(19|20)\d\d$/', $year) ? (int) $year : (int) CarbonImmutable::today()->year,
        );
    }

    /**
     * The statuses the department recap counts: Approved unless another status, or all, was chosen.
     */
    public function recapStatus(): ?RequestStatus
    {
        return $this->allStatuses ? null : ($this->status ?? RequestStatus::Approved);
    }

    /**
     * Limit a query to the chosen department, or to users without one.
     */
    public function applyDepartment(Builder $query, string $column): void
    {
        if ($this->department === self::NO_DEPARTMENT) {
            $query->whereNull($column);
        } elseif ($this->department !== null) {
            $query->where($column, $this->department);
        }
    }

    /**
     * The filters in effect, by key, as plain strings: for export links and the export audit row.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public function only(array $keys): array
    {
        $values = [
            'employee' => $this->employee,
            'department' => $this->department,
            'type' => $this->type,
            'category' => $this->category,
            'status' => $this->allStatuses ? self::ALL_STATUSES : $this->status?->value,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'periods' => $this->allPeriods ? 'all' : null,
            'year' => $this->year,
        ];

        $result = [];

        foreach ($keys as $key) {
            if ($values[$key] !== null) {
                $result[$key] = (string) $values[$key];
            }
        }

        return $result;
    }

    private static function id(string $value): ?int
    {
        return preg_match('/^[1-9]\d{0,17}$/', $value) ? (int) $value : null;
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        // 2026-02-30 would roll over into March: refuse it instead.
        return $date && $date->toDateString() === $value ? $date : null;
    }
}
