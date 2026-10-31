<?php

namespace App\Livewire\Users;

use App\Enums\PermissionName;
use App\Exceptions\AccountProtectionException;
use App\Models\LeaveBalanceEntry;
use App\Models\User;
use App\Support\LeaveBalances;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class LeaveBalance extends Component
{
    private const REASON_MAX_LENGTH = 500;

    private const ENTRIES_SHOWN = 50;

    private const EARLIEST_ANCHOR = '1950-01-01';

    #[Locked]
    public int $userId;

    public string $entitlementChange = '0';

    public string $usedChange = '0';

    public string $reason = '';

    public string $anchorDate = '';

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
        $this->authorize(PermissionName::LeaveBalanceView->value);
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
        $this->userId = User::query()->findOrFail($user)->getKey();
    }

    public function openAdjust(): void
    {
        $this->authorize(PermissionName::LeaveBalanceAdjust->value);

        $this->reset(['entitlementChange', 'usedChange', 'reason', 'notice']);
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'leave-adjust');
    }

    public function adjust(): void
    {
        $this->authorize(PermissionName::LeaveBalanceAdjust->value);

        $this->reason = trim($this->reason);

        $validated = $this->validate([
            'entitlementChange' => ['required', 'integer', 'between:-'.LeaveBalances::MAX_DAYS.','.LeaveBalances::MAX_DAYS],
            'usedChange' => ['required', 'integer', 'between:-'.LeaveBalances::MAX_DAYS.','.LeaveBalances::MAX_DAYS],
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX_LENGTH],
        ], ['reason.required' => 'Say why the balance changes.']);

        if ((int) $validated['entitlementChange'] === 0 && (int) $validated['usedChange'] === 0) {
            $this->addError('entitlementChange', 'Enter a change.');

            return;
        }

        $this->guarded(function () use ($validated) {
            LeaveBalances::adjust(
                $this->account(),
                (int) $validated['entitlementChange'],
                (int) $validated['usedChange'],
                $validated['reason'],
                Auth::user(),
            );

            $this->dispatch('close-modal', name: 'leave-adjust');
            $this->dispatch('toast', message: 'Balance adjusted.', tone: 'success');
        }, 'leave-adjust');
    }

    public function openAnchor(): void
    {
        $this->authorize(PermissionName::LeaveBalanceAdjust->value);

        $this->reset('notice');
        $this->resetValidation();
        $this->anchorDate = $this->account()->leave_anchor_date->toDateString();
        $this->dispatch('open-modal', name: 'leave-anchor');
    }

    public function saveAnchor(): void
    {
        $this->authorize(PermissionName::LeaveBalanceAdjust->value);

        $validated = $this->validate([
            'anchorDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST_ANCHOR, 'before_or_equal:today'],
        ], ['anchorDate.before_or_equal' => 'The reset date cannot be in the future.']);

        LeaveBalances::changeAnchor($this->account(), CarbonImmutable::parse($validated['anchorDate']), Auth::user());

        $this->dispatch('close-modal', name: 'leave-anchor');
        $this->dispatch('toast', message: 'Reset date changed.', tone: 'success');
    }

    public function openReset(): void
    {
        $this->authorize(PermissionName::LeaveBalanceAdjust->value);

        $this->reset('notice');
        $this->dispatch('open-modal', name: 'leave-reset');
    }

    public function resetBalance(): void
    {
        $this->authorize(PermissionName::LeaveBalanceAdjust->value);

        $this->guarded(function () {
            LeaveBalances::resetNow($this->account(), Auth::user(), 'ui');

            $this->dispatch('close-modal', name: 'leave-reset');
            $this->dispatch('toast', message: 'A new period has started.', tone: 'success');
        }, 'leave-reset');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = User::query()->with('roles')->findOrFail($this->userId);
        $periods = $user->leavePeriods()->orderByDesc('start_date')->get();

        return view('livewire.users.leave-balance', [
            'user' => $user,
            'current' => $periods->firstWhere('closed_at', null),
            'periods' => $periods,
            'entries' => LeaveBalanceEntry::query()
                ->with('actor:id,name')
                ->whereIn('period_id', $periods->pluck('id'))
                ->latest('id')
                ->limit(self::ENTRIES_SHOWN)
                ->get(),
        ])->title('Leave balance of '.$user->name)->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'User Management', 'href' => route('users.index')],
            ['label' => $user->name, 'href' => route('users.show', $user->id)],
            ['label' => 'Leave balance'],
        ]]);
    }

    private function account(): User
    {
        return User::query()->findOrFail($this->userId);
    }

    private function guarded(callable $action, string $dialog): void
    {
        try {
            $action();
        } catch (AccountProtectionException $exception) {
            $this->dispatch('close-modal', name: $dialog);
            $this->notice = $exception->getMessage();
        }
    }
}
