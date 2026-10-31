<?php

namespace App\Livewire\Users;

use App\Actions\UpdateEmployment;
use App\Exceptions\AccountProtectionException;
use App\Models\Department;
use App\Models\User;
use App\Support\SupervisorRelations;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Employment extends Component
{
    #[Locked]
    public int $userId;

    public string $departmentId = '';

    public string $joinedAt = '';

    public string $supervisorId = '';

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
        $this->authorize('viewAny', User::class);
    }

    /**
     * Refuse anything but a plain string, which is all the form can send.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.') || ! is_string($value), 422);
    }

    /**
     * The ID stays a plain route parameter, as in Show: binding would run before the permission check.
     */
    public function mount(string $user): void
    {
        $account = User::query()->findOrFail($user);

        $this->userId = $account->getKey();
        $this->departmentId = (string) $account->department_id;
        $this->joinedAt = (string) $account->joined_at?->toDateString();
    }

    public function saveDetails(): void
    {
        $user = $this->account();

        $this->authorize('update', $user);

        app(UpdateEmployment::class)->handle($user, [
            'department_id' => $this->departmentId,
            'joined_at' => $this->joinedAt,
        ], Auth::user());

        $this->dispatch('toast', message: 'Changes saved.', tone: 'success');
    }

    public function addSupervisor(): void
    {
        $this->authorize('manageSupervisors', User::class);

        $supervisor = User::query()->find(filter_var($this->supervisorId, FILTER_VALIDATE_INT) ?: null);

        if (! $supervisor) {
            $this->addError('supervisorId', 'Choose a supervisor.');

            return;
        }

        $this->guarded(function () use ($supervisor) {
            SupervisorRelations::assign($this->account(), $supervisor, Auth::user());

            $this->supervisorId = '';
            $this->dispatch('toast', message: $supervisor->name.' was added as a supervisor.', tone: 'success');
        });
    }

    public function removeSupervisor(mixed $id): void
    {
        $this->authorize('manageSupervisors', User::class);

        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($id === false, 404);

        $supervisor = User::query()->findOrFail($id);

        $this->guarded(function () use ($supervisor) {
            SupervisorRelations::remove($this->account(), $supervisor, Auth::user());

            $this->dispatch('toast', message: $supervisor->name.' is no longer a supervisor of this user.', tone: 'success');
        });
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = User::query()->with(['roles', 'department'])->findOrFail($this->userId);
        $supervisors = $user->supervisors()->with('roles')->orderBy('name')->get();

        return view('livewire.users.employment', [
            'user' => $user,
            'supervisors' => $supervisors,
            'activeSupervisorIds' => $user->supervisors()->activeSupervisor()->pluck('users.id'),
            'departments' => Department::query()
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $user->department_id))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            'candidates' => User::query()
                ->activeSupervisor()
                ->whereKeyNot($user->getKey())
                ->whereNotIn('id', $supervisors->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name']),
        ])->title('Employment of '.$user->name)->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'User Management', 'href' => route('users.index')],
            ['label' => $user->name, 'href' => route('users.show', $user->id)],
            ['label' => 'Employment'],
        ]]);
    }

    private function account(): User
    {
        return User::query()->findOrFail($this->userId);
    }

    private function guarded(callable $action): void
    {
        $this->notice = '';

        try {
            $action();
        } catch (AccountProtectionException $exception) {
            $this->notice = $exception->getMessage();
        }
    }
}
