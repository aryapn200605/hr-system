<?php

namespace App\Livewire\History;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A request opened read-only from history, for whoever sees its row there but may not open it on My
 * requests or in Approvals (an HR Admin looking at an Employee's request, a supervisor who does not
 * decide that subordinate). No decision, no soft lock, and evidence only for those who may download it.
 */
class Show extends Component
{
    #[Locked]
    public string $type;

    #[Locked]
    public int $requestId;

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize('viewHistory', LeaveRequest::class);
    }

    /**
     * A request the user may not see is a 404, the same as one that does not exist.
     */
    public function mount(string $type, string $id): void
    {
        $this->type = $type;
        $this->requestId = (int) $id;
        $this->record();
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $record = $this->record();
        $record->load(['decider', 'user.department', 'user.roles']);

        if ($record instanceof LeaveRequest) {
            $record->load(['category', 'allocations']);
        }

        $title = $record instanceof LeaveRequest ? __('Leave request') : __('Overtime request');

        return view('livewire.history.show', [
            'record' => $record,
            'requester' => $record->user,
            'title' => $title,
            'canDownload' => Auth::user()->can('downloadEvidence', $record),
        ])->title($title)->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Request history'), 'href' => route('history.index')],
            ['label' => $title],
        ]]);
    }

    private function record(): LeaveRequest|OvertimeRequest
    {
        $model = $this->type === 'leave' ? LeaveRequest::class : OvertimeRequest::class;
        $record = $model::query()->findOrFail($this->requestId);

        abort_unless(Auth::user()->can('viewDetail', $record), 404);

        return $record;
    }
}
