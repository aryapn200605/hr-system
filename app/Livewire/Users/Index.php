<?php

namespace App\Livewire\Users;

use App\Actions\CreateUserWithInvitation;
use App\Actions\ResendInvitation;
use App\Actions\UpdateUser;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use App\Livewire\Concerns\HasArrayFilters;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Requests\SuspensionRejections;
use App\Services\SupervisorGuard;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use HasArrayFilters;
    use WithPagination;

    private const PER_PAGE_OPTIONS = ['10', '25', '50'];

    private const SEARCH_MAX_LENGTH = 100;

    private const SEARCH_COLUMNS = ['name', 'username', 'email'];

    private const FILTERS = ['search', 'roleFilter', 'statusFilter', 'supervisorFilter', 'perPage'];

    /**
     * The only value of the supervisor filter besides "any".
     */
    private const NO_SUPERVISOR = 'none';

    #[Url(except: '')]
    public string $search = '';

    /** @var list<string> role names; any of them */
    #[Url(as: 'role', except: [])]
    public array $roleFilter = [];

    /** @var list<string> statuses; any of them */
    #[Url(as: 'status', except: [])]
    public array $statusFilter = [];

    #[Url(as: 'supervisor', except: '')]
    public string $supervisorFilter = '';

    #[Url(as: 'per_page', except: '10')]
    public string $perPage = '10';

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $role = '';

    /**
     * The account the open dialog acts on. Actions read this and never take an ID from the client.
     */
    #[Locked]
    public ?int $targetId = null;

    #[Locked]
    public string $targetName = '';

    /**
     * Which risky action the confirmation dialog will run.
     */
    #[Locked]
    public string $confirming = '';

    /**
     * How many pending requests the suspension dialog says will be rejected. Read again at confirm;
     * the toast gives the number that was actually rejected.
     */
    #[Locked]
    public int $pendingRequests = 0;

    /**
     * Whether the edit dialog shows the username and email as read only. The server decides again on save.
     */
    #[Locked]
    public bool $identityLocked = false;

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

    protected function arrayFilters(): array
    {
        return ['roleFilter' => 'role', 'statusFilter' => 'status'];
    }

    /**
     * Refuse anything but a plain string for a property, or a list of strings for the role and status
     * filters. A form never sends anything else, so anything else is a forged request.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_unless($this->arrayFilterUpdate($name, $value) ?? (! str_contains($name, '.') && is_string($value)), 422);
    }

    /**
     * Go back to the first page when a filter changes.
     */
    public function updated(string $name): void
    {
        if (in_array(explode('.', $name)[0], self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        $this->authorize('create', User::class);

        $this->resetDialog();
        $this->dispatch('open-modal', name: 'user-form');
    }

    public function openEdit(mixed $id): void
    {
        $user = $this->findUser($id);

        $this->authorize('update', $user);

        $this->selectTarget($user);
        $this->name = $user->name;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->identityLocked = Auth::user()->cannot('updateIdentity', $user);
        $this->dispatch('open-modal', name: 'user-form');
    }

    /**
     * Create a user, or save the user being edited.
     */
    public function saveUser(): void
    {
        $this->targetId === null ? $this->createUser() : $this->updateUser();
    }

    public function openRole(mixed $id): void
    {
        $user = $this->findUser($id);

        $this->authorize('changeAnyRole', $user);

        $this->selectTarget($user);
        $this->role = (string) $user->roleName();
        $this->dispatch('open-modal', name: 'user-role');
    }

    public function saveRole(): void
    {
        $user = $this->findUser($this->targetId);
        $role = RoleName::tryFrom($this->role);

        if (! $role) {
            $this->addError('role', __('Choose a valid role.'));

            return;
        }

        $this->authorize('changeRole', [$user, $role]);

        if ($user->roleName() === $role->value) {
            $this->closeDialogs();

            return;
        }

        $this->guarded(function () use ($user, $role) {
            $user->changeRole($role, Auth::user());

            $this->closeDialogs();
            $this->dispatch('toast', message: __(':name is now :role.', ['name' => $user->name, 'role' => $role->label()]), tone: 'success');
        });
    }

    public function openSuspend(mixed $id): void
    {
        $user = $this->findUser($id);

        $this->authorize('suspend', $user);

        $this->openConfirmation($user, 'suspend');
        $this->pendingRequests = app(SuspensionRejections::class)->pendingCount($user);
    }

    public function openReactivate(mixed $id): void
    {
        $user = $this->findUser($id);

        $this->authorize('reactivate', $user);

        $this->openConfirmation($user, 'reactivate');
    }

    public function openResend(mixed $id): void
    {
        $user = $this->findUser($id);

        $this->authorize('resendInvitation', $user);

        $this->openConfirmation($user, 'resend');
    }

    /**
     * Run the action the confirmation dialog was opened for, on the account as it is now.
     */
    public function confirm(): void
    {
        $user = $this->findUser($this->targetId);

        $this->guarded(fn () => match ($this->confirming) {
            'suspend' => $this->suspend($user),
            'reactivate' => $this->reactivate($user),
            'resend' => $this->resend($user),
            default => abort(404),
        });
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $this->normalizeFilters();

        $users = $this->users();

        if ($users->isEmpty() && $users->currentPage() > 1) {
            $this->setPage($users->lastPage());

            $users = $this->users();
        }

        return view('livewire.users.index', [
            'users' => $users,
            'roles' => RoleName::cases(),
            'statuses' => UserStatus::cases(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'assignableRoles' => app(UserPolicy::class)->assignableRoles(Auth::user()),
        ])->title(__('User Management'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('User Management')],
        ]]);
    }

    /**
     * Replace filter values that were not offered by the page. The URL and the update payload are client input.
     */
    private function normalizeFilters(): void
    {
        // mb_substr() also replaces invalid UTF-8, which would otherwise break the query and the snapshot.
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX_LENGTH);

        $this->roleFilter = $this->keepOffered($this->roleFilter, array_column(RoleName::cases(), 'value'));
        $this->statusFilter = $this->keepOffered($this->statusFilter, array_column(UserStatus::cases(), 'value'));

        if ($this->supervisorFilter !== self::NO_SUPERVISOR) {
            $this->supervisorFilter = '';
        }

        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = self::PER_PAGE_OPTIONS[0];
        }
    }

    /**
     * Get one page of users. The columns are named so no secret is ever loaded,
     * and roles and permissions come at once for the checks each row makes.
     */
    private function users(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return User::query()
            ->select(['id', 'name', 'username', 'email', 'status', 'department_id', 'two_factor_enrolled_at', 'last_login_at', 'created_at'])
            ->selectRaw('password is not null as activated')
            ->selectSub(
                app(SupervisorGuard::class)->activeSupervisors()->selectRaw('count(*) > 0')->whereColumn('su.subordinate_id', 'users.id'),
                'supervised',
            )
            ->with(['roles.permissions', 'permissions', 'department:id,name'])
            ->when($term !== '', function (Builder $query) use ($term) {
                $pattern = '%'.addcslashes($term, '\\%_').'%';

                $query->where(function (Builder $query) use ($pattern) {
                    foreach (self::SEARCH_COLUMNS as $column) {
                        $query->orWhere($column, 'like', $pattern);
                    }
                });
            })
            ->when($this->roleFilter !== [], fn (Builder $query) => $query->whereHas(
                'roles',
                fn (Builder $roles) => $roles->whereIn('name', $this->roleFilter),
            ))
            ->when($this->statusFilter !== [], fn (Builder $query) => $query->whereIn('status', $this->statusFilter))
            ->when($this->supervisorFilter === self::NO_SUPERVISOR, fn (Builder $query) => $query->lackingSupervisor())
            ->orderByDesc('id')
            ->paginate((int) $this->perPage);
    }

    private function createUser(): void
    {
        $this->authorize('create', User::class);

        $role = RoleName::tryFrom($this->role);

        // An invalid role is reported by the action's own validation.
        if ($role) {
            $this->authorize('assignRole', [User::class, $role]);
        }

        $result = app(CreateUserWithInvitation::class)->handle(
            $this->only(['name', 'username', 'email', 'role']),
            Auth::user(),
        );

        $this->closeDialogs();

        if ($result->sent) {
            $this->dispatch('toast', message: __('Invitation sent to :email.', ['email' => $result->user->email]), tone: 'success');
        } else {
            $this->notice = __('The account for :email was created, but the invitation email could not be sent. The account stays pending. Use Resend invitation to try again.', ['email' => $result->user->email]);
        }
    }

    private function updateUser(): void
    {
        $user = $this->findUser($this->targetId);

        $revoked = app(UpdateUser::class)->handle($user, $this->only(['name', 'username', 'email']), Auth::user());

        $this->closeDialogs();

        if ($revoked > 0) {
            $this->notice = __('Changes saved. The invitation already sent no longer works. Use Resend invitation to send a new one.');
        } else {
            $this->dispatch('toast', message: __('Changes saved.'), tone: 'success');
        }
    }

    private function suspend(User $user): void
    {
        $this->authorize('suspend', $user);

        $count = array_sum($user->suspend(Auth::user()));

        $this->closeDialogs();
        $this->dispatch('toast', message: $count > 0
            ? __(':name was suspended and signed out. :count pending requests were rejected.', ['name' => $user->name, 'count' => $count])
            : __(':name was suspended and signed out.', ['name' => $user->name]), tone: 'success');
    }

    private function reactivate(User $user): void
    {
        $this->authorize('reactivate', $user);

        $user->reactivate(Auth::user());

        $this->closeDialogs();
        $this->dispatch('toast', message: __(':name can sign in again.', ['name' => $user->name]), tone: 'success');
    }

    private function resend(User $user): void
    {
        $this->authorize('resendInvitation', $user);

        $result = app(ResendInvitation::class)->handle($user, Auth::user());

        $this->closeDialogs();

        if ($result->sent) {
            $this->dispatch('toast', message: __('A new invitation was sent to :email.', ['email' => $user->email]), tone: 'success');
        } else {
            $this->notice = __('The invitation email to :email could not be sent. The earlier link no longer works, so this user has no working link until a resend succeeds.', ['email' => $user->email]);
        }
    }

    /**
     * Run an action and show a refusal from the model's guards on the page, instead of an error page.
     */
    private function guarded(Closure $action): void
    {
        try {
            $action();
        } catch (AccountProtectionException $exception) {
            $this->closeDialogs();
            $this->notice = $exception->getMessage();
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['invitation'])) {
                throw $exception;
            }

            $this->closeDialogs();
            $this->notice = $exception->errors()['invitation'][0];
        }
    }

    /**
     * Load an account from an ID that came from the client. Anything that is not a valid ID is a 404.
     */
    private function findUser(mixed $id): User
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($id === false, 404);

        return User::query()->findOrFail($id);
    }

    private function selectTarget(User $user): void
    {
        $this->resetDialog();

        $this->targetId = $user->getKey();
        $this->targetName = $user->name;
    }

    private function openConfirmation(User $user, string $action): void
    {
        $this->selectTarget($user);

        $this->confirming = $action;
        $this->dispatch('open-modal', name: 'user-confirm');
    }

    private function resetDialog(): void
    {
        $this->reset(['name', 'username', 'email', 'role', 'targetId', 'targetName', 'confirming', 'pendingRequests', 'identityLocked', 'notice']);
        $this->resetValidation();
    }

    private function closeDialogs(): void
    {
        $this->resetDialog();

        foreach (['user-form', 'user-role', 'user-confirm'] as $name) {
            $this->dispatch('close-modal', name: $name);
        }
    }
}
