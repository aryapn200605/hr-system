<?php

namespace App\Livewire\Concerns;

/**
 * Filters that hold several values (a multi-select), kept in the URL with #[Url]. Values inside one filter
 * are combined with OR, filters with AND.
 *
 * - Updates must be a list of short strings, or one string at an index (Livewire sends "role.0" when a list
 *   keeps its length); anything else is a forged request and answers 422, like the other properties.
 * - A value the page does not offer is dropped by the component before the query (keepOffered()).
 * - An old link with one value (?role=hr_admin) still works: Livewire cannot put a string into an array
 *   property and would drop it, so mount turns it into a list of one.
 */
trait HasArrayFilters
{
    public const MAX_FILTER_VALUES = 50;

    private const MAX_FILTER_VALUE_LENGTH = 100;

    /**
     * The array filters of the component: property => name in the URL.
     *
     * @return array<string, string>
     */
    abstract protected function arrayFilters(): array;

    public function mountHasArrayFilters(): void
    {
        foreach ($this->arrayFilters() as $property => $key) {
            $value = request()->query($key);

            if (is_string($value) && $value !== '') {
                $this->{$property} = [$value];
            }
        }
    }

    /**
     * Whether an update is a valid change of an array filter; null when $name is not one.
     */
    protected function arrayFilterUpdate(string $name, mixed $value): ?bool
    {
        [$property, $index] = array_pad(explode('.', $name, 2), 2, null);

        if (! array_key_exists($property, $this->arrayFilters())) {
            return null;
        }

        $short = fn (mixed $item): bool => is_string($item) && strlen($item) <= self::MAX_FILTER_VALUE_LENGTH;

        if ($index === null) {
            return is_array($value) && array_is_list($value) && count($value) <= self::MAX_FILTER_VALUES && collect($value)->every($short);
        }

        return ctype_digit($index) && (int) $index < self::MAX_FILTER_VALUES && $short($value);
    }

    /**
     * Values shaped like record IDs, once each. Used for people chosen through a server search: an ID the
     * user may not see is kept and matches no row, so a forged value narrows a list and never widens it.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    protected function keepIds(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $id) => is_string($id) && preg_match('/^[1-9]\d{0,17}$/', $id))
            ->unique()
            ->take(self::MAX_FILTER_VALUES)
            ->values()
            ->all();
    }

    /**
     * The values that are offered, once each, in the order chosen.
     *
     * @param  array<mixed>  $values
     * @param  array<int, string>  $offered
     * @return list<string>
     */
    protected function keepOffered(array $values, array $offered): array
    {
        $offered = array_flip(array_map('strval', $offered));

        return collect($values)
            ->filter(fn (mixed $value) => is_string($value) && isset($offered[$value]))
            ->unique()
            ->values()
            ->all();
    }
}
