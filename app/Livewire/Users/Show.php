<?php

namespace App\Livewire\Users;

use App\Actions\UpdateEmployment;
use App\Enums\RoleName;
use App\Exceptions\AccountProtectionException;
use App\Models\Department;
use App\Models\User;
use App\Services\SupervisorGuard;
use App\Services\Supervisors;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class Show extends Component
{
    /**
     * Most Approvers one supervisor search returns.
     */
    public const SEARCH_LIMIT = 20;

    #[Locked]
    public int $userId;

    public string $departmentId = '';

    public string $joinDate = '';

    public string $supervisor = '';

    public string $target = '';

    /**
     * IDs of the subordinates chosen in the move dialog. Empty with $moveAll means every one.
     *
     * @var list<string>
     */
    public array $selection = [];

    public bool $moveAll = true;

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
        $this->authorize('viewAny', User::class);
    }

    /**
     * The ID stays a plain route parameter. Route model binding runs before the permission
     * middleware, and its 404 would tell any logged-in user which accounts exist.
     */
    public function mount(string $user): void
    {
        $this->userId = User::query()->findOrFail($user)->getKey();
    }

    /**
     * Refuse anything but the type each property holds. A form never sends anything else.
     */
    public function updating(string $name, mixed $value): void
    {
        $valid = match (true) {
            $name === 'moveAll' => is_bool($value),
            $name === 'selection' => is_array($value) && array_is_list($value) && collect($value)->every(fn ($id) => is_string($id)),
            str_starts_with($name, 'selection.') => is_string($value),
            default => ! str_contains($name, '.') && is_string($value),
        };

        abort_unless($valid, 422);
    }

    public function openEmployment(): void
    {
        $user = $this->user();

        $this->authorize('update', $user);

        $this->resetDialog();
        $this->departmentId = (string) $user->department_id;
        $this->joinDate = (string) $user->join_date?->toDateString();
        $this->dispatch('open-modal', name: 'employment-form');
    }

    public function saveEmployment(): void
    {
        $user = $this->user();

        try {
            $changed = app(UpdateEmployment::class)->handle($user, [
                'department_id' => $this->departmentId,
                'join_date' => $this->joinDate,
            ], Auth::user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [Str::camel($key) => $messages])->all());
        }

        $this->closeDialogs();
        $this->dispatch('toast', message: $changed === [] ? __('Nothing changed.') : __('Changes saved.'), tone: 'success');
    }

    public function openAddSupervisor(): void
    {
        $this->authorize('manageSupervisors', $this->user());

        $this->resetDialog();
        $this->dispatch('open-modal', name: 'supervisor-add');
    }

    public function addSupervisor(): void
    {
        $user = $this->user();

        app(Supervisors::class)->attach(Auth::user(), $user, $this->supervisor);

        $this->closeDialogs();
        $this->dispatch('toast', message: __('Supervisor added.'), tone: 'success');
    }

    public function removeSupervisor(mixed $supervisorId): void
    {
        $user = $this->user();

        $this->guarded(function () use ($user, $supervisorId) {
            app(Supervisors::class)->detach(Auth::user(), $user, $supervisorId);

            $this->dispatch('toast', message: __('Supervisor removed.'), tone: 'success');
        });
    }

    public function openMove(): void
    {
        $this->authorize('manageSupervisors', $this->user());

        $this->resetDialog();
        $this->dispatch('open-modal', name: 'subordinates-move');
    }

    public function moveSubordinates(): void
    {
        $user = $this->user();

        $this->guarded(function () use ($user) {
            $moved = app(Supervisors::class)->move(Auth::user(), $user, $this->target, $this->moveAll ? null : $this->selection);

            $this->closeDialogs();
            $this->dispatch('toast', message: __('Subordinates moved: :count.', ['count' => $moved]), tone: 'success');
        });
    }

    /**
     * The supervisor search of the add dialog: at most SEARCH_LIMIT active Approvers who do not supervise
     * this user yet. Only for whoever may manage this user's supervisors.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Renderless]
    public function searchCandidates(mixed $term = ''): array
    {
        $user = $this->user();

        $this->authorize('manageSupervisors', $user);

        return $this->approverOptions($this->candidates($user), $term);
    }

    /**
     * The new-supervisor search of the move dialog: at most SEARCH_LIMIT active Approvers but this user.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Renderless]
    public function searchTargets(mixed $term = ''): array
    {
        $user = $this->user();

        $this->authorize('manageSupervisors', $user);

        // Only an Approver has subordinates to move.
        if ($user->roleName() !== RoleName::Approver->value) {
            return [];
        }

        return $this->approverOptions(User::query()->activeApprover()->whereKeyNot($user->getKey()), $term);
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = $this->user()->load(['roles', 'department']);
        $actor = Auth::user();

        $supervisors = $user->supervisors()->with('roles')->orderBy('name')->get();
        $subordinates = $user->subordinates()->with('roles')->orderBy('name')->get();
        $counted = app(SupervisorGuard::class)->activeSupervisors()->where('su.subordinate_id', $user->getKey())->pluck('su.supervisor_id')->map(fn ($id) => (int) $id)->all();

        $canManage = $actor->can('manageSupervisors', $user);
        // The lists themselves are searched on the server (searchCandidates, searchTargets); the page only
        // needs to know whether there is anyone to choose, and the name of a choice already made.
        $hasCandidates = $canManage && $this->candidates($user)->exists();
        $chosen = $canManage ? array_filter([$this->supervisor, $this->target], fn (string $id) => ctype_digit($id)) : [];

        return view('livewire.users.show', [
            'user' => $user,
            'supervisors' => $supervisors,
            'subordinates' => $subordinates,
            'counted' => $counted,
            'missingSupervisor' => $user->lacksSupervisor(),
            'canUpdate' => $actor->can('update', $user),
            'canManage' => $canManage,
            'hasCandidates' => $hasCandidates,
            'approverLabels' => $chosen === [] ? [] : User::query()->activeApprover()->whereKey($chosen)->get(['id', 'name', 'username'])
                ->mapWithKeys(fn (User $approver) => [(string) $approver->getKey() => $approver->name.' ('.$approver->username.')'])->all(),
            'departments' => Department::query()
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $user->department_id))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
        ])
            ->title($user->name)
            ->layoutData(['breadcrumbs' => [
                ['label' => __('Home'), 'href' => route('dashboard')],
                ['label' => __('User Management'), 'href' => route('users.index')],
                ['label' => $user->name],
            ]]);
    }

    private function user(): User
    {
        return User::query()->findOrFail($this->userId);
    }

    /**
     * Active Approvers who may be added as this user's supervisor: not the user, not a supervisor already.
     *
     * @return Builder<User>
     */
    private function candidates(User $user): Builder
    {
        return User::query()->activeApprover()
            ->whereKeyNot($user->getKey())
            ->whereNotIn('users.id', $user->supervisors()->select('users.id'));
    }

    /**
     * Approvers matching a search term by name or username, as select options.
     *
     * @param  Builder<User>  $query
     * @return list<array{value: string, label: string}>
     */
    private function approverOptions(Builder $query, mixed $term): array
    {
        $term = is_string($term) ? trim(mb_substr($term, 0, 100)) : '';
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        return $query
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', $pattern)->orWhere('username', 'like', $pattern)))
            ->orderBy('name')->orderBy('id')
            ->limit(self::SEARCH_LIMIT)
            ->get(['id', 'name', 'username'])
            ->map(fn (User $approver) => ['value' => (string) $approver->getKey(), 'label' => $approver->name.' ('.$approver->username.')'])
            ->all();
    }

    /**
     * Run an action and show a refusal of the guards on the page, instead of an error page.
     */
    private function guarded(Closure $action): void
    {
        try {
            $action();
        } catch (AccountProtectionException $exception) {
            $this->closeDialogs();
            $this->notice = $exception->getMessage();
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['supervisor'])) {
                throw $exception;
            }

            $this->notice = $exception->errors()['supervisor'][0];
        }
    }

    private function resetDialog(): void
    {
        $this->reset(['departmentId', 'joinDate', 'supervisor', 'target', 'selection', 'moveAll', 'notice']);
        $this->resetValidation();
    }

    private function closeDialogs(): void
    {
        $this->resetDialog();

        foreach (['employment-form', 'supervisor-add', 'subordinates-move'] as $name) {
            $this->dispatch('close-modal', name: $name);
        }
    }
}
