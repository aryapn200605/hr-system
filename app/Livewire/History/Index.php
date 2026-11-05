<?php

namespace App\Livewire\History;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Models\Department;
use App\Models\LeaveCategory;
use App\Models\LeaveRequest;
use App\Services\Reports\HistoryRow;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\RequestHistory;
use App\Services\Reports\Visibility;
use App\Services\Requests\Deciders;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Request history: the user's own requests, those of the users linked to them as supervisor, or
 * everyone's (App\Services\Reports\Visibility), with filters and a CSV export of what is shown.
 */
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    /**
     * The filters this page has; the export link carries the same ones.
     */
    public const FILTERS = ['employee', 'department', 'type', 'category', 'status', 'from', 'to'];

    #[Url(except: '')]
    public string $employee = '';

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

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

    public function updated(string $name): void
    {
        // A category belongs to leave only.
        if ($name === 'type' && $this->type === 'overtime') {
            $this->category = '';
        }

        if ($name === 'category' && $this->category !== '') {
            $this->type = 'leave';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->resetPage();
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = Auth::user();
        $visibility = app(Visibility::class);
        $filters = ReportFilters::from($this->only(self::FILTERS));

        $page = app(RequestHistory::class)->query($user, $filters)->paginate(self::PER_PAGE);
        $page->setCollection($page->getCollection()->map(fn (object $record) => HistoryRow::fromRecord($record)));

        return view('livewire.history.index', [
            'rows' => $page,
            'links' => $this->links($page->getCollection()),
            'seesOthers' => $visibility->seesOthers($user),
            'employees' => $visibility->seesOthers($user) ? $visibility->users($user) : collect(),
            'departments' => $visibility->seesOthers($user) ? Department::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'categories' => LeaveCategory::query()->orderBy('id')->get(),
            'statuses' => RequestStatus::cases(),
            'exportUrl' => route('history.export', $filters->only(self::FILTERS)),
            'filtered' => $filters->only(self::FILTERS) !== [],
        ])->title(__('Request history'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Request history')],
        ]]);
    }

    /**
     * The detail page each row may open, by "type-id", using the existing pages and their rules: the
     * requester's own page, or the Approvals page for whoever may decide the request now or decided it
     * (App\Policies\RequestPolicy). Every other row opens read-only in history (history.show). One query
     * for the page.
     *
     * @param  Collection<int, HistoryRow>  $rows
     * @return array<string, string>
     */
    private function links($rows): array
    {
        $user = Auth::user();
        $submits = $user->hasPermissionTo(PermissionName::RequestsSubmit);
        $decides = $user->hasPermissionTo(PermissionName::RequestsDecide);
        $others = $rows->pluck('userId')->reject(fn (int $id) => $id === $user->getKey())->unique()->values();

        $decidable = $decides && $others->isNotEmpty()
            ? app(Deciders::class)->requesterIds($user)->whereIn('users.id', $others)->pluck('users.id')->flip()
            : collect();

        $links = [];

        foreach ($rows as $row) {
            $parameters = ['type' => $row->type, 'id' => $row->id];

            if ($row->userId === $user->getKey() && $submits) {
                $links[$row->type.'-'.$row->id] = route('requests.show', $parameters);
            } elseif ($decides && ($row->decidedBy === $user->getKey() || $decidable->has($row->userId))) {
                $links[$row->type.'-'.$row->id] = route('approvals.show', $parameters);
            } else {
                // Every row on the page passed Visibility, which is RequestPolicy::viewDetail's rule.
                $links[$row->type.'-'.$row->id] = route('history.show', $parameters);
            }
        }

        return $links;
    }
}
