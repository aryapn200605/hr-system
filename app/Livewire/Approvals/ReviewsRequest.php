<?php

namespace App\Livewire\Approvals;

use App\Actions\DecideRequest;
use App\Enums\PermissionName;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\RequestLock;
use App\Support\RequestDecisions;
use App\Support\RequestLocks;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * The page where a decider opens a request. Opening it takes the soft lock, and the polling
 * heartbeat keeps it. Everyone else who may decide sees it read only until the lock ends.
 *
 * An ID that is unknown or out of reach gets the same 403, so it reveals nothing.
 */
trait ReviewsRequest
{
    private const REASON_MIN = 5;

    private const TEXT_MAX = 1000;

    #[Locked]
    public int $requestId;

    public string $note = '';

    public string $reason = '';

    /**
     * Why a decision did not go through. Written by the server only.
     */
    #[Locked]
    public string $notice = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize(PermissionName::RequestsDecide->value);
    }

    /**
     * Refuse anything but a plain string, which is all the form can send.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.') || ! is_string($value), 422);
    }

    public function mount(string $request): void
    {
        $this->requestId = $this->findReachable($request)->getKey();

        $this->heartbeat();
    }

    /**
     * Keep or take the lock while the page is open. findReachable() reads the rights again,
     * so someone who lost them while the page was open stops here.
     */
    public function heartbeat(): void
    {
        $request = $this->findReachable($this->requestId);

        if ($request->isPending()) {
            RequestLocks::acquire($request, Auth::user());
        }
    }

    public function approve(): void
    {
        $validated = $this->validate(['note' => ['nullable', 'string', 'max:'.self::TEXT_MAX]]);

        $this->conclude(app(DecideRequest::class)->approve($this->findRequest(), Auth::user(), trim($validated['note'])), 'request-approve', 'Request approved.');
    }

    public function reject(): void
    {
        $this->reason = trim($this->reason);

        $validated = $this->validate(
            ['reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::TEXT_MAX]],
            ['reason.required' => 'Say why the request is rejected.'],
        );

        $this->conclude(app(DecideRequest::class)->reject($this->findRequest(), Auth::user(), $validated['reason']), 'request-reject', 'Request rejected.');
    }

    /**
     * @return class-string<LeaveRequest|OvertimeRequest>
     */
    abstract protected function model(): string;

    /**
     * @return array<string, mixed>
     */
    private function shared(LeaveRequest|OvertimeRequest $request): array
    {
        $holder = $request->isPending() ? RequestLocks::holder($request) : null;
        $canDecide = $request->isPending() && RequestDecisions::canDecide(Auth::user(), $request);

        return [
            'request' => $request,
            'kind' => $request::KIND,
            'holder' => $this->someoneElse($holder) ? $holder : null,
            'mayDecide' => $canDecide && ! $this->someoneElse($holder),
            'evidenceUrl' => route('requests.'.$request::KIND.'.evidence', $request->getKey()),
        ];
    }

    private function someoneElse(?RequestLock $holder): bool
    {
        return $holder !== null && $holder->user_id !== Auth::id();
    }

    private function conclude(?string $problem, string $dialog, string $toast): void
    {
        $this->dispatch('close-modal', name: $dialog);

        $this->notice = $problem ?? '';

        if ($problem === null) {
            $this->reset(['note', 'reason']);
            $this->dispatch('toast', message: $toast, tone: 'success');
        }
    }

    private function findRequest(): LeaveRequest|OvertimeRequest
    {
        return $this->model()::query()->findOrFail($this->requestId);
    }

    /**
     * A page stays open to someone who may decide the request and to whoever decided it.
     */
    private function findReachable(int|string $id): LeaveRequest|OvertimeRequest
    {
        $request = $this->model()::query()->find($id);

        abort_unless(
            $request && ($request->decided_by === Auth::id() || RequestDecisions::canDecide(Auth::user(), $request)),
            403,
        );

        return $request;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function breadcrumbs(string $title): array
    {
        return [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'Approvals', 'href' => route('approvals.index')],
            ['label' => $title],
        ];
    }
}
