<?php

namespace App\Livewire\Recap;

use App\Enums\RequestStatus;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Services\Reports\BalanceRecap;
use App\Services\Reports\DepartmentRecap;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\Visibility;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Recap: leave balance per employee and period, and requests per department and month. Both show only
 * the users App\Services\Reports\Visibility allows, and both export what is shown as CSV.
 */
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    public const BALANCE_FILTERS = ['employee', 'department', 'periods'];

    public const DEPARTMENT_FILTERS = ['department', 'year', 'status'];

    private const TABS = ['balance', 'departments'];

    #[Url(except: 'balance')]
    public string $tab = 'balance';

    #[Url(except: '')]
    public string $employee = '';

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $periods = '';

    #[Url(except: '')]
    public string $year = '';

    #[Url(except: '')]
    public string $status = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize('viewHistory', LeaveRequest::class);
    }

    /**
     * Refuse anything but a plain string for a property.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.') || ! is_string($value), 422);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = Auth::user();
        $visibility = app(Visibility::class);
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'balance';
        $seesOthers = $visibility->seesOthers($user);

        $data = [
            'shownTab' => $tab,
            'seesOthers' => $seesOthers,
            'departments' => $seesOthers ? Department::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ];

        // Each tab reads only its own filters, so a filter set on the other tab never narrows this one unseen.
        $filters = ReportFilters::from($this->only($tab === 'balance' ? self::BALANCE_FILTERS : self::DEPARTMENT_FILTERS));

        if ($tab === 'balance') {
            $recap = app(BalanceRecap::class);
            $users = $recap->users($user, $filters)->paginate(self::PER_PAGE);

            $data += [
                'users' => $users,
                'lines' => $recap->lines($users->getCollection(), $filters->allPeriods),
                'employees' => $seesOthers ? $visibility->users($user) : collect(),
                'exportUrl' => route('recap.balance.export', $filters->only(self::BALANCE_FILTERS)),
            ];
        } else {
            $recap = app(DepartmentRecap::class);

            $data += [
                'rows' => $recap->rows($user, $filters),
                'categories' => $recap->categories(),
                'years' => $recap->years(),
                'thisYear' => today()->year,
                'shownYear' => $filters->year,
                'statuses' => RequestStatus::cases(),
                'exportUrl' => route('recap.departments.export', $filters->only(self::DEPARTMENT_FILTERS)),
            ];
        }

        return view('livewire.recap.index', $data)->title(__('Recap'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Recap')],
        ]]);
    }
}
