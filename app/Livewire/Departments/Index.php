<?php

namespace App\Livewire\Departments;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Index extends Component
{
    private const NAME_MAX_LENGTH = 100;

    private const TAKEN_MESSAGE = 'A department with this name already exists.';

    public string $name = '';

    /**
     * The department the open dialog acts on. Actions read this and never take an ID from the client.
     */
    #[Locked]
    public ?int $targetId = null;

    #[Locked]
    public string $targetName = '';

    /**
     * A warning that must stay on screen, unlike a toast. Written by the server only.
     */
    #[Locked]
    public string $notice = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize(PermissionName::DepartmentsManage->value);
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
        $this->dispatch('open-modal', name: 'department-form');
    }

    public function openEdit(mixed $id): void
    {
        $department = $this->findDepartment($id);

        $this->resetDialog();
        $this->targetId = $department->getKey();
        $this->targetName = $department->name;
        $this->name = $department->name;
        $this->dispatch('open-modal', name: 'department-form');
    }

    public function openDelete(mixed $id): void
    {
        $department = $this->findDepartment($id);

        $this->resetDialog();
        $this->targetId = $department->getKey();
        $this->targetName = $department->name;
        $this->dispatch('open-modal', name: 'department-delete');
    }

    public function save(): void
    {
        $department = $this->targetId === null ? new Department : $this->findDepartment($this->targetId);

        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH, Rule::unique(Department::class)->ignore($department)],
        ], ['name.unique' => self::TAKEN_MESSAGE]);

        $department->fill($validated);

        if (! $department->isDirty()) {
            $this->closeDialogs();

            return;
        }

        try {
            DB::transaction(function () use ($department) {
                $event = $department->exists ? AuditEvent::DepartmentUpdated : AuditEvent::DepartmentCreated;

                $department->save();

                AuditLog::record($event, Auth::user(), $department, ['name' => $department->name]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests passed validation with the same name, and the index is the last check.
            $this->addError('name', self::TAKEN_MESSAGE);

            return;
        }

        $this->closeDialogs();
        $this->dispatch('toast', message: 'Department saved.', tone: 'success');
    }

    public function toggleActive(mixed $id): void
    {
        $department = $this->findDepartment($id);

        DB::transaction(function () use ($department) {
            $department->is_active = ! $department->is_active;
            $department->save();

            AuditLog::record(
                $department->is_active ? AuditEvent::DepartmentReactivated : AuditEvent::DepartmentDeactivated,
                Auth::user(),
                $department,
                ['name' => $department->name],
            );
        });

        $this->dispatch('toast', message: $department->name.($department->is_active ? ' is active again.' : ' was deactivated.'), tone: 'success');
    }

    /**
     * Delete a department nobody uses. The foreign key is the check, so it also holds for
     * a user moved in meanwhile, and the audit entry goes with the rollback.
     */
    public function delete(): void
    {
        $department = $this->findDepartment($this->targetId);

        try {
            DB::transaction(function () use ($department) {
                AuditLog::record(AuditEvent::DepartmentDeleted, Auth::user(), $department, ['name' => $department->name]);

                $department->delete();
            });
        } catch (QueryException) {
            $this->closeDialogs();
            $this->notice = $department->name.' is assigned to users, so it cannot be deleted. Deactivate it instead.';

            return;
        }

        $this->closeDialogs();
        $this->dispatch('toast', message: 'Department deleted.', tone: 'success');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.departments.index', [
            'departments' => Department::query()->withCount('users')->orderBy('name')->get(),
        ])->title('Departments')->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'Departments'],
        ]]);
    }

    /**
     * Load a department from an ID that came from the client. Anything that is not a valid ID is a 404.
     */
    private function findDepartment(mixed $id): Department
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($id === false, 404);

        return Department::query()->findOrFail($id);
    }

    private function resetDialog(): void
    {
        $this->reset(['name', 'targetId', 'targetName', 'notice']);
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
