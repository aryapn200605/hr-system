<?php

namespace App\Livewire;

use App\Enums\RequestStatus;
use Illuminate\Support\Collection;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The Livewire half of the component gallery (/ui-kit): the form components bound with wire:model, array
 * values, values changed by the server, and a server search. Like the gallery it answers 404 unless APP_ENV
 * is local, also for Livewire updates. The searched list is made-up sample names, never users.
 */
class UiKitDemo extends Component
{
    public const SEARCH_LIMIT = 20;

    private const FIRST = ['Andi', 'Budi', 'Citra', 'Dewi', 'Eko', 'Fajar', 'Gita', 'Hadi', 'Intan', 'Joko'];

    private const LAST = ['Pratama', 'Saputra', 'Wijaya', 'Lestari', 'Nugroho', 'Kusuma', 'Santoso', 'Hidayat', 'Putri', 'Halim'];

    public string $status = '';

    /** @var list<string> */
    public array $statuses = [];

    public string $person = '';

    /** @var list<string> */
    public array $people = [];

    public string $date = '';

    public string $from = '';

    public string $to = '';

    public string $hour = '17:00';

    public string $code = '';

    public function boot(): void
    {
        abort_unless(app()->isLocal(), 404);
    }

    /**
     * Only the types each property holds; arrays only as lists of strings.
     */
    public function updating(string $name, mixed $value): void
    {
        $valid = in_array($name, ['statuses', 'people'], true)
            ? is_array($value) && array_is_list($value) && collect($value)->every(fn ($item) => is_string($item))
            : ! str_contains($name, '.') && is_string($value);

        abort_unless($valid, 422);
    }

    /**
     * Change every value from the server, to show that the components follow.
     */
    public function setFromServer(): void
    {
        $this->status = RequestStatus::Approved->value;
        $this->statuses = [RequestStatus::Pending->value, RequestStatus::Rejected->value];
        $this->person = '7';
        $this->people = ['12', '45', '78'];
        $this->date = today()->addDays(3)->toDateString();
        $this->from = today()->startOfMonth()->toDateString();
        $this->to = today()->endOfMonth()->toDateString();
        $this->hour = '08:00';
        $this->code = '123456';
    }

    public function clearAll(): void
    {
        $this->reset();
    }

    /**
     * The server search the remote select calls: at most SEARCH_LIMIT matches.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Renderless]
    public function searchPeople(mixed $term = ''): array
    {
        $term = is_string($term) ? mb_strtolower(trim(mb_substr($term, 0, 100))) : '';

        return $this->samples()
            ->filter(fn (string $name) => $term === '' || str_contains(mb_strtolower($name), $term))
            ->take(self::SEARCH_LIMIT)
            ->map(fn (string $name, int $id) => ['value' => (string) $id, 'label' => $name])
            ->values()
            ->all();
    }

    public function render(): mixed
    {
        $samples = $this->samples();

        return view('livewire.ui-kit-demo', [
            'statusOptions' => collect(RequestStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all(),
            'personLabels' => $samples->only([$this->person, ...$this->people])->all(),
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function samples(): Collection
    {
        $names = [];
        $id = 1;

        foreach (self::LAST as $last) {
            foreach (self::FIRST as $first) {
                $names[$id++] = $first.' '.$last;
            }
        }

        return collect($names);
    }
}
