<?php

namespace App\Livewire\Departments;

use App\Enums\PermissionName;
use App\Models\Department;
use App\Services\Departments;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Index extends Component
{
    public string $name = '';

    public bool $active = true;

    /**
     * The department the open dialog acts on. Actions read this and never take an ID from the client.
     */
    #[Locked]
    public ?int $targetId = null;

    #[Locked]
    public string $targetName = '';

    /**
     * A refusal that must stay on screen, unlike a toast. Written by the server only.
     */
    #[Locked]
    public string $notice = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        abort_unless(Auth::user()->hasPermissionTo(PermissionName::DepartmentsManage), 403);
    }

    /**
     * Refuse anything but the type each property holds. A form never sends anything else.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.'), 422);
        abort_if($name === 'active' ? ! is_bool($value) : ! is_string($value), 422);
    }

    public function openCreate(): void
    {
        $this->resetDialog();
        $this->dispatch('open-modal', name: 'department-form');
    }

    public function openEdit(mixed $id): void
    {
        $department = $this->find($id);

        $this->resetDialog();
        $this->targetId = $department->getKey();
        $this->targetName = $department->name;
        $this->name = $department->name;
        $this->active = $department->is_active;
        $this->dispatch('open-modal', name: 'department-form');
    }

    public function openDelete(mixed $id): void
    {
        $department = $this->find($id);

        $this->resetDialog();
        $this->targetId = $department->getKey();
        $this->targetName = $department->name;
        $this->dispatch('open-modal', name: 'department-delete');
    }

    public function save(): void
    {
        $service = app(Departments::class);

        if ($this->targetId === null) {
            $department = $service->create(Auth::user(), $this->name);
            $message = __('Department :name was created.', ['name' => $department->name]);
        } else {
            $department = $service->update(Auth::user(), $this->targetId, $this->name, $this->active);
            $message = __('Changes saved.');
        }

        $this->closeDialogs();
        $this->dispatch('toast', message: $message, tone: 'success');
    }

    public function delete(): void
    {
        try {
            app(Departments::class)->delete(Auth::user(), $this->targetId);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['department'])) {
                throw $exception;
            }

            $this->closeDialogs();
            $this->notice = $exception->errors()['department'][0];

            return;
        }

        $name = $this->targetName;
        $this->closeDialogs();
        $this->dispatch('toast', message: __('Department :name was deleted.', ['name' => $name]), tone: 'success');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.departments.index', [
            'departments' => Department::query()->withCount('users')->orderBy('name')->get(),
        ])->title(__('Departments'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Departments')],
        ]]);
    }

    /**
     * Load a department from an ID that came from the client. Anything that is not a valid ID is a 404.
     */
    private function find(mixed $id): Department
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($id === false, 404);

        return Department::query()->findOrFail($id);
    }

    private function resetDialog(): void
    {
        $this->reset(['name', 'active', 'targetId', 'targetName', 'notice']);
        $this->resetValidation();
    }

    private function closeDialogs(): void
    {
        $this->resetDialog();

        foreach (['department-form', 'department-delete'] as $name) {
            $this->dispatch('close-modal', name: $name);
        }
    }
}
