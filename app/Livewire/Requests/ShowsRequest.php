<?php

namespace App\Livewire\Requests;

use App\Actions\CancelRequest;
use App\Enums\PermissionName;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * The detail page of one of the user's own requests. An ID that is unknown
 * or belongs to someone else gets the same 403, so it reveals nothing.
 */
trait ShowsRequest
{
    #[Locked]
    public int $requestId;

    /**
     * A warning that must stay on screen, unlike a toast. Written by the server only.
     */
    #[Locked]
    public string $notice = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize(PermissionName::RequestsCreate->value);
    }

    public function mount(string $request): void
    {
        $this->requestId = $this->findOwn($request)->getKey();
    }

    public function cancel(): void
    {
        $request = $this->findOwn($this->requestId);

        $this->dispatch('close-modal', name: 'request-cancel');

        if (app(CancelRequest::class)->handle($request, Auth::user())) {
            $this->notice = '';
            $this->dispatch('toast', message: 'The request was cancelled.', tone: 'success');
        } else {
            $this->notice = 'This request is no longer pending, so it cannot be cancelled.';
        }
    }

    /**
     * @return class-string<LeaveRequest|OvertimeRequest>
     */
    abstract protected function model(): string;

    private function findOwn(int|string $id): LeaveRequest|OvertimeRequest
    {
        $request = $this->model()::query()->find($id);

        abort_unless($request && Auth::user()->can('own', $request), 403);

        return $request;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function breadcrumbs(string $title): array
    {
        return [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'My requests', 'href' => route('requests.index')],
            ['label' => $title],
        ];
    }
}
