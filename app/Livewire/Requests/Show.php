<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public string $type;

    #[Locked]
    public int $requestId;

    /**
     * A refusal that must stay on screen, unlike a toast. Written by the server only.
     */
    #[Locked]
    public string $notice = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize('create', LeaveRequest::class);
    }

    /**
     * Someone else's request is a 404, the same as one that does not exist.
     */
    public function mount(string $type, string $id): void
    {
        $this->type = $type;
        $this->requestId = (int) $id;
        $this->record();
    }

    /**
     * A page opened before the request was cancelled or decided gets the reason instead of the dialog.
     */
    public function openCancel(): void
    {
        $record = $this->record();

        if ($record->status !== RequestStatus::Pending) {
            $this->notice = __('This request is no longer pending: it is :status.', ['status' => $record->status->label()]);

            return;
        }

        $this->authorize('cancel', $record);

        $this->notice = '';
        $this->dispatch('open-modal', name: 'request-cancel');
    }

    public function cancel(): void
    {
        $service = $this->type === 'leave' ? app(LeaveRequests::class) : app(OvertimeRequests::class);

        try {
            $service->cancel(Auth::user(), $this->requestId);
        } catch (ValidationException $exception) {
            $this->dispatch('close-modal', name: 'request-cancel');
            $this->notice = $exception->errors()['request'][0] ?? $exception->getMessage();

            return;
        }

        $this->dispatch('close-modal', name: 'request-cancel');
        $this->dispatch('toast', message: __('The request was cancelled.'), tone: 'success');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $record = $this->record();
        $record->load('decider');

        if ($record instanceof LeaveRequest) {
            $record->load(['category', 'allocations']);
        }

        $title = $record instanceof LeaveRequest ? __('Leave request') : __('Overtime request');

        return view('livewire.requests.show', [
            'record' => $record,
            'title' => $title,
            'canCancel' => Auth::user()->can('cancel', $record),
        ])->title($title)->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('My requests'), 'href' => route('requests.index', $this->type === 'leave' ? [] : ['type' => 'overtime'])],
            ['label' => $title],
        ]]);
    }

    private function record(): LeaveRequest|OvertimeRequest
    {
        $model = $this->type === 'leave' ? LeaveRequest::class : OvertimeRequest::class;
        $record = $model::query()->findOrFail($this->requestId);

        abort_unless(Auth::user()->can('view', $record), 404);

        return $record;
    }
}
