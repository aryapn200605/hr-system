<?php

namespace App\Livewire;

use App\Enums\LoginResult;
use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Dashboard extends Component
{
    /**
     * Days covered by the login summary.
     */
    private const SUMMARY_DAYS = 30;

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize(PermissionName::DashboardView->value);
    }

    /**
     * Reload the whole page, because a Livewire update does not render the sidebar and its role badge.
     */
    public function refresh(): void
    {
        session()->flash('toast', __('Dashboard refreshed.'));

        $this->redirectRoute('dashboard');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = Auth::user();

        $loginResults = $user->loginHistories()
            ->where('occurred_at', '>=', now()->subDays(self::SUMMARY_DAYS))
            ->pluck('result');

        return view('livewire.dashboard', [
            'user' => $user,
            'summaryDays' => self::SUMMARY_DAYS,
            'recoveryCodesLeft' => $user->recoveryCodes()->whereNull('used_at')->count(),
            'successfulLogins' => $loginResults->filter(fn (string $result) => $result === LoginResult::Success->value)->count(),
            'failedLogins' => $loginResults->reject(fn (string $result) => $result === LoginResult::Success->value)->count(),
            'recentLogins' => $user->loginHistories()->latest('occurred_at')->latest('id')->limit(5)->get(),
            'accountCounts' => $user->can(PermissionName::UsersView->value) ? $this->accountCounts() : null,
        ])->title(__('Home'))->layoutData(['breadcrumbs' => [['label' => __('Home')]]]);
    }

    /**
     * Count accounts per status. Only for users who may see the user list.
     *
     * @return array<string, int>
     */
    private function accountCounts(): array
    {
        return User::query()->toBase()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }
}
