<?php

namespace App\Livewire\Approvals;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Requests\Deciders;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Approvals inbox: pending requests the user may decide now, oldest first, and the requests the
 * user decided. Both kinds of request are listed together, or filtered by type.
 */
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    private const TABS = ['waiting', 'decided'];

    private const TYPES = ['leave', 'overtime'];

    #[Url(except: 'waiting')]
    public string $tab = 'waiting';

    #[Url(except: '')]
    public string $type = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize('decideAny', LeaveRequest::class);
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
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'waiting';
        $type = in_array($this->type, self::TYPES, true) ? $this->type : '';

        return view('livewire.approvals.index', [
            'shownTab' => $tab,
            'shownType' => $type,
            'rows' => $this->rows($tab, $type),
        ])->title(__('Approvals'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Approvals')],
        ]]);
    }

    /**
     * One page of both request tables, paginated together, with the models loaded.
     */
    private function rows(string $tab, string $type): LengthAwarePaginator
    {
        $user = Auth::user();
        $parts = [];

        foreach ([LeaveRequest::class => 'leave', OvertimeRequest::class => 'overtime'] as $model => $name) {
            if ($type !== '' && $type !== $name) {
                continue;
            }

            $query = $model::query()->toBase()->select([DB::raw("'$name' as type"), 'id', 'created_at', 'decided_at']);

            $parts[] = $tab === 'waiting'
                ? $query->where('status', RequestStatus::Pending->value)->whereIn('user_id', app(Deciders::class)->requesterIds($user))
                : $query->where('decided_by', $user->getKey());
        }

        $union = array_shift($parts);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $page = $tab === 'waiting'
            ? $union->orderBy('created_at')->orderBy('id')
            : $union->orderByDesc('decided_at')->orderByDesc('id');

        $page = $page->paginate(self::PER_PAGE);

        $leave = LeaveRequest::query()->with(['user', 'category', 'decider'])
            ->whereKey($page->getCollection()->where('type', 'leave')->pluck('id'))->get()->keyBy('id');
        $overtime = OvertimeRequest::query()->with(['user', 'decider'])
            ->whereKey($page->getCollection()->where('type', 'overtime')->pluck('id'))->get()->keyBy('id');

        return $page->through(fn (object $row) => $row->type === 'leave' ? $leave[$row->id] : $overtime[$row->id]);
    }
}
