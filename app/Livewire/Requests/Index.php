<?php

namespace App\Livewire\Requests;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Support\RequestEligibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize(PermissionName::RequestsCreate->value);
    }

    /**
     * Refuse anything but a plain string, which is all the filter can send.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.') || ! is_string($value), 422);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        if (! RequestStatus::tryFrom($this->statusFilter)) {
            $this->statusFilter = '';
        }

        return view('livewire.requests.index', [
            'requests' => $this->requests(),
            'statuses' => RequestStatus::cases(),
            'blockedReason' => RequestEligibility::blockedReason(Auth::user()),
        ])->title('My requests')->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'My requests'],
        ]]);
    }

    /**
     * Get one page of the user's own leave and overtime requests, newest first. The columns
     * are named, so no evidence path reaches the page.
     */
    private function requests(): LengthAwarePaginator
    {
        $userId = Auth::id();

        $leave = DB::table('leave_requests')
            ->join('leave_categories', 'leave_categories.id', '=', 'leave_requests.leave_category_id')
            ->where('leave_requests.user_id', $userId)
            ->when($this->statusFilter !== '', fn ($query) => $query->where('leave_requests.status', $this->statusFilter))
            ->selectRaw("'leave' as kind, leave_requests.id, leave_categories.name as label, leave_requests.start_date as starts, leave_requests.end_date as ends, leave_requests.working_days as amount, leave_requests.status, leave_requests.created_at");

        $overtime = DB::table('overtime_requests')
            ->where('user_id', $userId)
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->selectRaw("'overtime' as kind, id, 'Overtime' as label, start_at as starts, end_at as ends, hours as amount, status, created_at");

        return $leave->union($overtime)->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE);
    }
}
