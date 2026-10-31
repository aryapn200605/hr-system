<?php

namespace App\Livewire\Holidays;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\PublicHoliday;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    private const NAME_MAX_LENGTH = 150;

    private const TAKEN_MESSAGE = 'This date already has a holiday.';

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
        $this->authorize(PermissionName::HolidaysManage->value);
    }

    /**
     * Refuse anything but a plain string, which is all the form can send.
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
        $holiday = $this->findHoliday($id);

        $this->resetDialog();
        $this->targetId = $holiday->getKey();
        $this->targetName = $holiday->name;
        $this->date = $holiday->date->toDateString();
        $this->name = $holiday->name;
        $this->dispatch('open-modal', name: 'holiday-form');
    }

    public function openDelete(mixed $id): void
    {
        $holiday = $this->findHoliday($id);

        $this->resetDialog();
        $this->targetId = $holiday->getKey();
        $this->targetName = $holiday->name;
        $this->dispatch('open-modal', name: 'holiday-delete');
    }

    public function save(): void
    {
        $holiday = $this->targetId === null ? new PublicHoliday : $this->findHoliday($this->targetId);

        $this->name = trim($this->name);

        $validated = $this->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::unique(PublicHoliday::class)->ignore($holiday)],
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
        ], ['date.unique' => self::TAKEN_MESSAGE]);

        $holiday->fill($validated);

        if (! $holiday->exists) {
            $holiday->created_by = Auth::id();
        }

        if (! $holiday->isDirty()) {
            $this->closeDialogs();

            return;
        }

        try {
            DB::transaction(function () use ($holiday) {
                $event = $holiday->exists ? AuditEvent::PublicHolidayUpdated : AuditEvent::PublicHolidayCreated;

                $holiday->save();

                AuditLog::record($event, Auth::user(), $holiday, $this->details($holiday));
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests passed validation with the same date, and the index is the last check.
            $this->addError('date', self::TAKEN_MESSAGE);

            return;
        }

        $this->closeDialogs();
        $this->dispatch('toast', message: 'Holiday saved.', tone: 'success');
    }

    /**
     * Delete a holiday. Requests already made keep their day counts, which were frozen when made.
     */
    public function delete(): void
    {
        $holiday = $this->findHoliday($this->targetId);

        DB::transaction(function () use ($holiday) {
            AuditLog::record(AuditEvent::PublicHolidayDeleted, Auth::user(), $holiday, $this->details($holiday));

            $holiday->delete();
        });

        $this->closeDialogs();
        $this->dispatch('toast', message: 'Holiday deleted.', tone: 'success');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.holidays.index', [
            'holidays' => PublicHoliday::query()->orderByDesc('date')->paginate(self::PER_PAGE),
        ])->title('Public holidays')->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'Public holidays'],
        ]]);
    }

    /**
     * @return array{date: string, name: string}
     */
    private function details(PublicHoliday $holiday): array
    {
        return ['date' => $holiday->date->toDateString(), 'name' => $holiday->name];
    }

    /**
     * Load a holiday from an ID that came from the client. Anything that is not a valid ID is a 404.
     */
    private function findHoliday(mixed $id): PublicHoliday
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($id === false, 404);

        return PublicHoliday::query()->findOrFail($id);
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
