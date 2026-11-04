<?php

namespace App\Livewire\Users;

use App\Enums\PermissionName;
use App\Models\LeaveBalanceEntry;
use App\Models\User;
use App\Services\LeaveBalance as Balance;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class LeaveBalance extends Component
{
    use WithPagination;

    private const ENTRIES_PER_PAGE = 15;

    #[Locked]
    public int $userId;

    public string $days = '';

    public string $reason = '';

    public string $anchor = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', User::class);
        abort_unless(Auth::user()->hasPermissionTo(PermissionName::LeaveBalanceView), 403);
    }

    /**
     * The ID stays a plain route parameter, for the reason given in Users\Show::mount().
     */
    public function mount(string $user): void
    {
        $this->userId = User::query()->findOrFail($user)->getKey();
    }

    /**
     * Refuse anything but a plain string for a property. Every property a form can change is one.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.') || ! is_string($value), 422);
    }

    public function openAdjust(): void
    {
        $this->authorize('adjustLeaveBalance', $this->user());

        $this->resetDialog();
        $this->dispatch('open-modal', name: 'balance-adjust');
    }

    public function adjust(): void
    {
        $period = app(Balance::class)->adjust(Auth::user(), $this->user(), $this->days, $this->reason);

        $this->closeDialogs();
        $this->dispatch('toast', message: __('Balance saved. :count days remain.', ['count' => $period->remaining()]), tone: 'success');
    }

    public function openReset(): void
    {
        $this->authorize('adjustLeaveBalance', $this->user());

        $this->resetDialog();
        $this->dispatch('open-modal', name: 'balance-reset');
    }

    public function resetBalance(): void
    {
        app(Balance::class)->reset(Auth::user(), $this->user(), $this->reason);

        $this->closeDialogs();
        $this->dispatch('toast', message: __('A new period with :count days started today.', ['count' => Balance::ENTITLEMENT]), tone: 'success');
    }

    public function openAnchor(): void
    {
        $user = $this->user();

        $this->authorize('adjustLeaveBalance', $user);

        $this->resetDialog();
        $this->anchor = $user->leave_anchor_date->toDateString();
        $this->dispatch('open-modal', name: 'balance-anchor');
    }

    /**
     * Put the account creation date (WIB) into the anchor field. The server checks it again on save.
     */
    public function useCreationDate(): void
    {
        $this->authorize('adjustLeaveBalance', $this->user());

        $this->anchor = $this->user()->created_at->toDateString();
    }

    public function saveAnchor(): void
    {
        app(Balance::class)->changeAnchor(Auth::user(), $this->user(), $this->anchor);

        $this->closeDialogs();
        $this->dispatch('toast', message: __('Anchor date saved. It applies from the next period.'), tone: 'success');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = $this->user()->load('roles');
        $current = app(Balance::class)->sync($user);

        return view('livewire.users.leave-balance', [
            'user' => $user,
            'current' => $current,
            'periods' => $user->leavePeriods()->orderByDesc('starts_on')->get(),
            'entries' => LeaveBalanceEntry::query()
                ->where('user_id', $user->getKey())
                ->with(['period:id,starts_on,ends_on', 'actor:id,name'])
                ->orderByDesc('id')
                ->paginate(self::ENTRIES_PER_PAGE),
            'canAdjust' => Auth::user()->can('adjustLeaveBalance', $user),
            'isSelf' => Auth::user()->is($user),
            'creationDate' => $user->created_at->toDateString(),
        ])
            ->title(__('Leave balance of :name', ['name' => $user->name]))
            ->layoutData(['breadcrumbs' => [
                ['label' => __('Home'), 'href' => route('dashboard')],
                ['label' => __('User Management'), 'href' => route('users.index')],
                ['label' => $user->name, 'href' => route('users.show', $user->id)],
                ['label' => __('Leave balance')],
            ]]);
    }

    private function user(): User
    {
        return User::query()->findOrFail($this->userId);
    }

    private function resetDialog(): void
    {
        $this->reset(['days', 'reason', 'anchor']);
        $this->resetValidation();
    }

    private function closeDialogs(): void
    {
        $this->resetDialog();

        foreach (['balance-adjust', 'balance-reset', 'balance-anchor'] as $name) {
            $this->dispatch('close-modal', name: $name);
        }
    }
}
