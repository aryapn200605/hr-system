<?php

namespace App\Livewire\Holidays;

use App\Enums\PermissionName;
use App\Models\Holiday;
use App\Services\Holidays;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url(except: '')]
    public string $year = '';

    public string $date = '';

    public string $name = '';

    /**
     * The holiday the open dialog acts on. Actions read this and never take an ID from the client.
     */
    #[Locked]
    public ?int $targetId = null;

    #[Locked]
    public string $targetName = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        abort_unless(Auth::user()->hasPermissionTo(PermissionName::HolidaysManage), 403);
    }

    /**
     * Refuse anything but a plain string for a property. Every property a form can change is one.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.') || ! is_string($value), 422);
    }

    public function openCreate(): void
    {
        $this->resetDialog();
        $this->dispatch('open-modal', name: 'holiday-form');
    }

    public function openEdit(mixed $id): void
    {
        $holiday = $this->find($id);

        $this->resetDialog();
        $this->targetId = $holiday->getKey();
        $this->targetName = $holiday->name;
        $this->date = $holiday->date->toDateString();
        $this->name = $holiday->name;
        $this->dispatch('open-modal', name: 'holiday-form');
    }

    public function openDelete(mixed $id): void
    {
        $holiday = $this->find($id);

        $this->resetDialog();
        $this->targetId = $holiday->getKey();
        $this->targetName = $holiday->name;
        $this->dispatch('open-modal', name: 'holiday-delete');
    }

    public function save(): void
    {
        $service = app(Holidays::class);

        $holiday = $this->targetId === null
            ? $service->create(Auth::user(), $this->date, $this->name)
            : $service->update(Auth::user(), $this->targetId, $this->date, $this->name);

        $this->year = (string) $holiday->date->year;
        $this->closeDialogs();
        $this->dispatch('toast', message: __('Holiday saved. Existing requests keep their working days.'), tone: 'success');
    }

    public function delete(): void
    {
        app(Holidays::class)->delete(Auth::user(), $this->targetId);

        $name = $this->targetName;
        $this->closeDialogs();
        $this->dispatch('toast', message: __('Holiday :name was deleted.', ['name' => $name]), tone: 'success');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $years = $this->years();
        $year = in_array($this->year, $years, true) ? (int) $this->year : (int) now()->year;

        return view('livewire.holidays.index', [
            'years' => $years,
            'shownYear' => $year,
            'holidays' => Holiday::query()->whereYear('date', $year)->orderBy('date')->get(),
            'earliest' => Holidays::EARLIEST,
            'latest' => Holidays::latest(),
        ])->title(__('Holidays'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Holidays')],
        ]]);
    }

    /**
     * The years offered by the filter: every year with a holiday, plus this year up to the last one allowed.
     *
     * @return list<string>
     */
    private function years(): array
    {
        $stored = Holiday::query()->selectRaw('DISTINCT YEAR(date) AS y')->pluck('y')->map(fn ($y) => (string) $y);
        $range = collect(range((int) now()->year, (int) now()->year + Holidays::YEARS_AHEAD))->map(fn ($y) => (string) $y);

        return $stored->merge($range)->unique()->sortDesc()->values()->all();
    }

    /**
     * Load a holiday from an ID that came from the client. Anything that is not a valid ID becomes
     * false, which findOrFail() also answers with a 404.
     */
    private function find(mixed $id): Holiday
    {
        return Holiday::query()->findOrFail(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
    }

    private function resetDialog(): void
    {
        $this->reset(['date', 'name', 'targetId', 'targetName']);
        $this->resetValidation();
    }

    private function closeDialogs(): void
    {
        $this->resetDialog();

        foreach (['holiday-form', 'holiday-delete'] as $name) {
            $this->dispatch('close-modal', name: $name);
        }
    }
}
