<?php

namespace App\Livewire\Approvals;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Support\RequestDecisions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    private const TABS = ['pending', 'decided'];

    private const KINDS = ['leave', 'overtime'];

    #[Url(except: 'pending')]
    public string $tab = 'pending';

    #[Url(as: 'kind', except: '')]
    public string $kindFilter = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize(PermissionName::RequestsDecide->value);
    }

    /**
     * Refuse anything but a plain string, which is all the page can send.
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
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'pending';
        }

        if (! in_array($this->kindFilter, self::KINDS, true)) {
            $this->kindFilter = '';
        }

        return view('livewire.approvals.index', [
            'requests' => $this->tab === 'pending' ? $this->pending() : $this->decided(),
            'pendingCount' => RequestDecisions::pendingCount(Auth::user()),
        ])->title('Approvals')->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'Approvals'],
        ]]);
    }

    private function pending(): LengthAwarePaginator
    {
        $requesters = RequestDecisions::requesterIds(Auth::user());

        return $this->rows(fn (Builder $query, string $table) => $query
            ->where($table.'.status', RequestStatus::Pending->value)
            ->whereIn($table.'.user_id', $requesters))
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    private function decided(): LengthAwarePaginator
    {
        return $this->rows(fn (Builder $query, string $table) => $query->where($table.'.decided_by', Auth::id()))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Combine both kinds of request. The columns are named, so no evidence path reaches the page.
     */
    private function rows(callable $scope): Builder
    {
        $leave = DB::table('leave_requests')
            ->join('users', 'users.id', '=', 'leave_requests.user_id')
            ->join('leave_categories', 'leave_categories.id', '=', 'leave_requests.leave_category_id')
            ->selectRaw("'leave' as kind, leave_requests.id, users.name as requester, leave_categories.name as label, leave_requests.start_date as starts, leave_requests.end_date as ends, leave_requests.working_days as amount, leave_requests.status, leave_requests.created_at, leave_requests.decided_at");

        $overtime = DB::table('overtime_requests')
            ->join('users', 'users.id', '=', 'overtime_requests.user_id')
            ->selectRaw("'overtime' as kind, overtime_requests.id, users.name as requester, 'Overtime' as label, overtime_requests.start_at as starts, overtime_requests.end_at as ends, overtime_requests.hours as amount, overtime_requests.status, overtime_requests.created_at, overtime_requests.decided_at");

        $scope($leave, 'leave_requests');
        $scope($overtime, 'overtime_requests');

        return match ($this->kindFilter) {
            'leave' => DB::query()->fromSub($leave, 'requests'),
            'overtime' => DB::query()->fromSub($overtime, 'requests'),
            default => DB::query()->fromSub($leave->unionAll($overtime), 'requests'),
        };
    }
}
