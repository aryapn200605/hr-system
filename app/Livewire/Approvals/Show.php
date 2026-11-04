<?php

namespace App\Livewire\Approvals;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Requests\Decisions;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Requests\ReviewLock;
use App\Services\Requests\ReviewLockHolder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One request as a decider sees it. Opening a pending request takes the soft review lock; the page
 * renews it every minute and lets go when closed. Anyone else who may decide sees it in view-only
 * mode with the holder's name. The decision itself is checked again by App\Services\Requests\Decisions.
 */
class Show extends Component
{
    #[Locked]
    public string $type;

    #[Locked]
    public int $requestId;

    /**
     * Whether this page took the soft lock and keeps renewing it. Written by the server only.
     */
    #[Locked]
    public bool $holdsLock = false;

    /**
     * A message that must stay on screen, unlike a toast. Written by the server only.
     */
    #[Locked]
    public string $notice = '';

    public string $note = '';

    public string $reason = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize('decideAny', LeaveRequest::class);
    }

    /**
     * A request the user may not review is a 404, the same as one that does not exist.
     */
    public function mount(string $type, string $id): void
    {
        $this->type = $type;
        $this->requestId = (int) $id;

        $record = Decisions::model($type)::query()->findOrFail($this->requestId);
        abort_unless(Auth::user()->can('review', $record), 404);

        if ($record->status === RequestStatus::Pending) {
            $this->takeLock();
        }
    }

    /**
     * Refuse anything but a plain string for a property. Only the two text fields can change.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.') || ! is_string($value), 422);
    }

    /**
     * Renew the lock while the page is open. A page that lost it (expired and taken by someone else)
     * switches to view-only mode.
     */
    public function heartbeat(): void
    {
        if (! $this->holdsLock) {
            return;
        }

        if ($this->record()?->status !== RequestStatus::Pending) {
            $this->holdsLock = false;

            return;
        }

        $this->takeLock();
    }

    /**
     * Start reviewing from view-only mode, once the other decider has let go.
     */
    public function startReview(): void
    {
        if ($this->record()?->status === RequestStatus::Pending) {
            $this->takeLock();
        }
    }

    /**
     * Let go of the lock and go back to the inbox.
     */
    public function close(): void
    {
        app(ReviewLock::class)->release(Auth::user(), Decisions::model($this->type), $this->requestId);
        $this->holdsLock = false;

        $this->redirectRoute('approvals.index');
    }

    public function openApprove(): void
    {
        if ($this->ready()) {
            $this->note = '';
            $this->resetErrorBag();
            $this->dispatch('open-modal', name: 'decision-approve');
        }
    }

    public function openReject(): void
    {
        if ($this->ready()) {
            $this->reason = '';
            $this->resetErrorBag();
            $this->dispatch('open-modal', name: 'decision-reject');
        }
    }

    public function approve(): void
    {
        $this->decide('decision-approve', fn () => app(Decisions::class)->approve(Auth::user(), $this->type, $this->requestId, $this->note), __('The request was approved.'));
    }

    public function reject(): void
    {
        $this->decide('decision-reject', fn () => app(Decisions::class)->reject(Auth::user(), $this->type, $this->requestId, $this->reason), __('The request was rejected.'));
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $record = $this->record();
        $title = $record instanceof OvertimeRequest || ($record === null && $this->type === 'overtime') ? __('Overtime request') : __('Leave request');
        $layout = ['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Approvals'), 'href' => route('approvals.index')],
            ['label' => $title],
        ]];

        if ($record === null) {
            // Access was lost while the page was open: show nothing of the request any more.
            return view('livewire.approvals.show', ['record' => null, 'title' => $title])->title($title)->layoutData($layout);
        }

        $record->load(['user.roles', 'user.department', 'decider']);
        $requester = $record->user;

        if ($record instanceof LeaveRequest) {
            $record->load(['category', 'allocations']);
        }

        $holder = app(ReviewLock::class)->holder(Decisions::model($this->type), $this->requestId);
        $pending = $record->status === RequestStatus::Pending;

        return view('livewire.approvals.show', [
            'record' => $record,
            'requester' => $requester,
            'title' => $title,
            'outcome' => app(Decisions::class)->outcome($record),
            'holder' => $holder,
            'viewOnly' => $pending && $holder !== null && ! $holder->isHeldBy(Auth::id()),
            'canDecide' => $pending && ($holder === null || $holder->isHeldBy(Auth::id())),
            'heldMessage' => $holder !== null ? app(Decisions::class)->heldMessage($holder) : '',
            'periods' => $record instanceof LeaveRequest ? app(LeaveRequests::class)->slots($requester) : [],
            'days' => $record instanceof OvertimeRequest ? app(OvertimeRequests::class)->dayTotals($record) : [],
            'heartbeat' => (int) config('requests.review_heartbeat_seconds'),
        ])->title($title)->layoutData($layout);
    }

    /**
     * Whether a decision dialog may open now. Otherwise the page says why, and nothing opens.
     */
    private function ready(): bool
    {
        $record = $this->record();

        if ($record === null) {
            return false;
        }

        if (($outcome = app(Decisions::class)->outcome($record)) !== null) {
            $this->notice = $outcome;

            return false;
        }

        $holder = $this->takeLock();

        if ($holder === null || ! $holder->isHeldBy(Auth::id())) {
            return false;
        }

        $this->notice = '';

        return true;
    }

    /**
     * @param  callable(): mixed  $decision
     */
    private function decide(string $modal, callable $decision, string $done): void
    {
        // The service validates, not the component, so an error fixed since the last try must go first.
        $this->resetErrorBag();

        try {
            $decision();
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['decision'])) {
                throw $exception;
            }

            $this->stop($modal, $exception->errors()['decision'][0]);

            return;
        } catch (AuthorizationException $exception) {
            $this->stop($modal, $exception->getMessage());

            return;
        }

        $this->holdsLock = false;
        $this->notice = '';
        $this->dispatch('close-modal', name: $modal);
        $this->dispatch('toast', message: $done, tone: 'success');
    }

    private function stop(string $modal, string $message): void
    {
        $this->dispatch('close-modal', name: $modal);
        $this->notice = $message;
    }

    /**
     * Take or renew the lock and remember whether it worked.
     */
    private function takeLock(): ?ReviewLockHolder
    {
        $holder = app(ReviewLock::class)->acquire(Auth::user(), Decisions::model($this->type), $this->requestId);
        $this->holdsLock = $holder !== null && $holder->isHeldBy(Auth::id());

        return $holder;
    }

    /**
     * The request while this user may still review it. Authority is read again on every call; when it
     * is gone (a supervisor link removed, a role changed), the page says so instead of failing.
     */
    private function record(): LeaveRequest|OvertimeRequest|null
    {
        $record = Decisions::model($this->type)::query()->findOrFail($this->requestId);

        if (Auth::user()->can('review', $record)) {
            return $record;
        }

        $this->holdsLock = false;
        $this->notice = __('You cannot decide this request.');

        return null;
    }
}
