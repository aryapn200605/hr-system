<?php

namespace App\Livewire\Approvals;

use App\Models\OvertimeRequest;
use Carbon\CarbonImmutable;
use Livewire\Component;

class ReviewOvertime extends Component
{
    use ReviewsRequest;

    protected function model(): string
    {
        return OvertimeRequest::class;
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $request = $this->findReachable($this->requestId)->load(['user', 'decider']);

        return view('livewire.approvals.review', $this->shared($request) + [
            'title' => 'Overtime request',
            'parts' => [],
            'hoursByDay' => $this->hoursByDay($request),
        ])->title('Overtime request')->layoutData(['breadcrumbs' => $this->breadcrumbs('Overtime request')]);
    }

    /**
     * Total the requester's pending and approved overtime on each date this request touches,
     * counting only the hours that fall on that date.
     *
     * @return array<string, int>
     */
    private function hoursByDay(OvertimeRequest $request): array
    {
        $totals = [];

        for ($day = $request->start_at->startOfDay(); $day->lessThan($request->end_at); $day = $day->addDay()) {
            $next = $day->addDay();

            $totals[$day->format('j M Y')] = OvertimeRequest::query()
                ->where('user_id', $request->user_id)
                ->holding()
                ->where('start_at', '<', $next)
                ->where('end_at', '>', $day)
                ->get()
                ->sum(fn (OvertimeRequest $other) => (int) CarbonImmutable::parse(max($other->start_at, $day))->diffInHours(min($other->end_at, $next)));
        }

        return $totals;
    }
}
