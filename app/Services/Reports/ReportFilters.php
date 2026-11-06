<?php

namespace App\Services\Reports;

use App\Enums\RequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The filters of history, recap and their exports, read from the page's properties or the export's
 * query string. Employee, department, type, category and status hold several values (since Phase 15b):
 * values inside one filter are combined with OR, filters with AND. Each accepts a list or, for links made
 * before, a single string. Only values in the expected shape are taken; anything else counts as "not
 * chosen", so a forged value can only narrow a list, never widen what Visibility allows.
 */
final class ReportFilters
{
    public const KEYS = ['employee', 'department', 'type', 'category', 'status', 'from', 'to', 'periods', 'year'];

    public const TYPES = ['leave', 'overtime'];

    public const NO_DEPARTMENT = 'none';

    public const ALL_STATUSES = 'all';

    /**
     * Values read per filter at most; more are ignored.
     */
    public const MAX_VALUES = 50;

    /**
     * @param  list<int>  $employees
     * @param  list<int|string>  $departments  department IDs and NO_DEPARTMENT
     * @param  list<string>  $types
     * @param  list<string>  $categories  category codes
     * @param  list<RequestStatus>  $statuses
     */
    private function __construct(
        public readonly array $employees,
        public readonly array $departments,
        public readonly array $types,
        public readonly array $categories,
        public readonly array $statuses,
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

        // A list, or one string from an older link; each value trimmed, empty ones dropped.
        $list = function (string $key) use ($input): array {
            $value = $input[$key] ?? null;
            $values = is_array($value) ? array_values($value) : [$value];

            return collect(array_slice($values, 0, self::MAX_VALUES))
                ->filter(fn (mixed $item) => is_string($item))
                ->map(fn (string $item) => trim($item))
                ->reject(fn (string $item) => $item === '')
                ->unique()
                ->values()
                ->all();
        };

        $from = self::date($text('from'));
        $to = self::date($text('to'));

        // A range typed the wrong way round is read the right way round.
        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $year = $text('year');
        $statuses = $list('status');

        return new self(
            employees: self::ids($list('employee')),
            departments: array_values(array_unique([
                ...(in_array(self::NO_DEPARTMENT, $list('department'), true) ? [self::NO_DEPARTMENT] : []),
                ...self::ids($list('department')),
            ], SORT_REGULAR)),
            types: array_values(array_intersect(self::TYPES, $list('type'))),
            categories: array_values(array_filter($list('category'), fn (string $code) => (bool) preg_match('/^[a-z0-9_-]{1,50}$/', $code))),
            statuses: array_values(array_filter(array_map(fn (string $status) => RequestStatus::tryFrom($status), $statuses))),
            allStatuses: in_array(self::ALL_STATUSES, $statuses, true),
            from: $from,
            to: $to,
            allPeriods: $text('periods') === 'all',
            year: preg_match('/^(19|20)\d\d$/', $year) ? (int) $year : (int) CarbonImmutable::today()->year,
        );
    }

    /**
     * The statuses the department recap counts: Approved unless other statuses, or all, were chosen.
     *
     * @return list<RequestStatus> empty for every status
     */
    public function recapStatuses(): array
    {
        return $this->allStatuses ? [] : ($this->statuses ?: [RequestStatus::Approved]);
    }

    /**
     * Whether the leave rows (or the overtime rows) are wanted: every type when none was chosen.
     */
    public function wantsType(string $type): bool
    {
        return $this->types === [] || in_array($type, $this->types, true);
    }

    /**
     * Limit a query to the chosen statuses.
     *
     * @param  list<RequestStatus>  $statuses
     */
    public static function applyStatuses(Builder $query, string $column, array $statuses): void
    {
        if ($statuses !== []) {
            $query->whereIn($column, array_map(fn (RequestStatus $status) => $status->value, $statuses));
        }
    }

    /**
     * Limit a query to the chosen employees.
     */
    public function applyEmployees(Builder $query, string $column): void
    {
        if ($this->employees !== []) {
            $query->whereIn($column, $this->employees);
        }
    }

    /**
     * Limit a query to the chosen departments, users without one included when "No department" was chosen.
     */
    public function applyDepartment(Builder $query, string $column): void
    {
        if ($this->departments === []) {
            return;
        }

        $ids = array_values(array_filter($this->departments, 'is_int'));
        $none = in_array(self::NO_DEPARTMENT, $this->departments, true);

        $query->where(function (Builder $query) use ($column, $ids, $none) {
            if ($ids !== []) {
                $query->whereIn($column, $ids);
            }

            if ($none) {
                $query->orWhereNull($column);
            }
        });
    }

    /**
     * The filters in effect, by key: lists of strings for the filters with several values, plain strings for
     * the others. For export links and the export audit row.
     *
     * @param  list<string>  $keys
     * @return array<string, string|list<string>>
     */
    public function only(array $keys): array
    {
        $strings = fn (array $values): array => array_map('strval', $values);

        $values = [
            'employee' => $strings($this->employees),
            'department' => $strings($this->departments),
            'type' => $this->types,
            'category' => $this->categories,
            'status' => $this->allStatuses ? [self::ALL_STATUSES] : array_map(fn (RequestStatus $status) => $status->value, $this->statuses),
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'periods' => $this->allPeriods ? 'all' : null,
            'year' => (string) $this->year,
        ];

        $result = [];

        foreach ($keys as $key) {
            if ($values[$key] !== null && $values[$key] !== []) {
                $result[$key] = $values[$key];
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        return array_values(array_unique(array_map('intval', array_filter($values, fn (string $value) => (bool) preg_match('/^[1-9]\d{0,17}$/', $value)))));
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
