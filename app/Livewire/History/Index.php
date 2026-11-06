<?php

namespace App\Livewire\History;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Livewire\Concerns\HasArrayFilters;
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
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Request history: the user's own requests, those of the users linked to them as supervisor, or
 * everyone's (App\Services\Reports\Visibility), with filters and a CSV export of what is shown.
 */
class Index extends Component
{
    use HasArrayFilters;
    use WithPagination;

    public const PER_PAGE = 25;

    /**
     * Most people one employee search returns.
     */
    public const SEARCH_LIMIT = 20;

    /**
     * The filters this page has; the export link carries the same ones.
     */
    public const FILTERS = ['employee', 'department', 'type', 'category', 'status', 'from', 'to'];

    /** @var list<string> user IDs */
    #[Url(except: [])]
    public array $employee = [];

    /** @var list<string> department IDs and ReportFilters::NO_DEPARTMENT */
    #[Url(except: [])]
    public array $department = [];

    /** @var list<string> */
    #[Url(except: [])]
    public array $type = [];

    /** @var list<string> category codes */
    #[Url(except: [])]
    public array $category = [];

    /** @var list<string> */
    #[Url(except: [])]
    public array $status = [];

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

    protected function arrayFilters(): array
    {
        return ['employee' => 'employee', 'department' => 'department', 'type' => 'type', 'category' => 'category', 'status' => 'status'];
    }

    /**
     * Refuse anything but a plain string for a property, or a list of strings for a filter with several values.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_unless($this->arrayFilterUpdate($name, $value) ?? (! str_contains($name, '.') && is_string($value)), 422);
    }

    public function updated(string $name): void
    {
        $name = explode('.', $name)[0];

        // A category belongs to leave only: choosing one means leave, and choosing overtime drops it.
        if ($name === 'type' && $this->type !== [] && $this->type !== ['leave']) {
            $this->category = [];
        }

        if ($name === 'category' && $this->category !== []) {
            $this->type = ['leave'];
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->resetPage();
    }

    /**
     * The employee filter's server search: at most SEARCH_LIMIT people the user may see, by name or
     * username. Nobody for a user who sees only their own requests.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Renderless]
    public function searchEmployees(mixed $term = ''): array
    {
        return app(Visibility::class)->search(Auth::user(), is_string($term) ? $term : '', self::SEARCH_LIMIT);
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = Auth::user();
        $visibility = app(Visibility::class);
        $seesOthers = $visibility->seesOthers($user);
        $categories = LeaveCategory::query()->orderBy('id')->get();
        $departments = $seesOthers ? Department::query()->orderBy('name')->get(['id', 'name']) : collect();

        $this->whitelist($categories->pluck('code')->all(), $departments->pluck('id')->all());
        $filters = ReportFilters::from($this->only(self::FILTERS));

        $page = app(RequestHistory::class)->query($user, $filters)->paginate(self::PER_PAGE);
        $page->setCollection($page->getCollection()->map(fn (object $record) => HistoryRow::fromRecord($record)));

        return view('livewire.history.index', [
            'rows' => $page,
            'links' => $this->links($page->getCollection()),
            'seesOthers' => $seesOthers,
            // Only the names of the chosen people the user may see; the rest come from searchEmployees().
            'employeeLabels' => $seesOthers ? $visibility->names($user, $filters->employees) : [],
            'departments' => $departments,
            'categories' => $categories,
            'statuses' => RequestStatus::cases(),
            'exportUrl' => route('history.export', $filters->only(self::FILTERS)),
            'filtered' => $filters->only(self::FILTERS) !== [],
        ])->title(__('Request history'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Request history')],
        ]]);
    }

    /**
     * Drop the values the page does not offer, so the URL and the query hold only those. Employee IDs are
     * only checked for their shape: an ID the user may not see is kept and matches no row, so a forged
     * value narrows the list and never widens it.
     *
     * @param  list<string>  $categories
     * @param  list<int>  $departments
     */
    private function whitelist(array $categories, array $departments): void
    {
        $this->employee = $this->keepIds($this->employee);
        $this->department = $this->keepOffered($this->department, [...$departments, ReportFilters::NO_DEPARTMENT]);
        $this->type = $this->keepOffered($this->type, ReportFilters::TYPES);
        $this->category = $this->keepOffered($this->category, $categories);
        $this->status = $this->keepOffered($this->status, array_map(fn (RequestStatus $status) => $status->value, RequestStatus::cases()));
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
