<?php

namespace App\Livewire\Approvals;

use App\Models\LeaveRequest;
use App\Support\LeaveDeduction;
use Livewire\Component;

class ReviewLeave extends Component
{
    use ReviewsRequest;

    protected function model(): string
    {
        return LeaveRequest::class;
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $request = $this->findReachable($this->requestId)->load(['user', 'category', 'decider']);

        return view('livewire.approvals.review', $this->shared($request) + [
            'title' => 'Leave request',
            'parts' => $request->status->value === 'pending' ? LeaveDeduction::plan($request, $request->user) : [],
            'hoursByDay' => [],
        ])->title('Leave request')->layoutData(['breadcrumbs' => $this->breadcrumbs('Leave request')]);
    }
}
