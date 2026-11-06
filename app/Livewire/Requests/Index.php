<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Models\Holiday;
use App\Models\LeaveCategory;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Requests\ApprovalPath;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Requests\RequestNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use WithFileUploads, WithPagination;

    private const PER_PAGE = 10;

    private const TYPES = ['leave', 'overtime'];

    /**
     * Properties that hold an uploaded file instead of a string.
     */
    private const FILE_PROPERTIES = ['evidence', 'overtime_evidence'];

    /**
     * Error keys of the overtime service mapped to the fields of this form.
     */
    private const OVERTIME_FIELDS = ['start_at' => 'start_date', 'end_at' => 'end_date', 'reason' => 'overtime_reason', 'evidence' => 'overtime_evidence'];

    #[Url(except: 'leave')]
    public string $type = 'leave';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public string $category = '';

    public string $starts_on = '';

    public string $ends_on = '';

    public string $reason = '';

    public ?TemporaryUploadedFile $evidence = null;

    public string $start_date = '';

    public string $start_hour = '';

    public string $end_date = '';

    public string $end_hour = '';

    public string $overtime_reason = '';

    public ?TemporaryUploadedFile $overtime_evidence = null;

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize('create', LeaveRequest::class);
    }

    /**
     * Refuse anything but a plain string, or an uploaded file for the evidence fields.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.'), 422);

        if (in_array($name, self::FILE_PROPERTIES, true)) {
            abort_unless($value === null || $value instanceof TemporaryUploadedFile, 422);

            return;
        }

        abort_unless(is_string($value), 422);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['type', 'statusFilter'], true)) {
            $this->resetPage();
        }

        // A new file replaces the one an earlier error was about. The server checks it again on submit.
        if (in_array($name, self::FILE_PROPERTIES, true)) {
            $this->resetErrorBag($name);
        }
    }

    public function openLeave(): void
    {
        $this->resetForms();
        $this->category = LeaveCategory::query()->active()->orderBy('id')->value('code') ?? '';
        $this->starts_on = $this->ends_on = today()->toDateString();
        $this->dispatch('open-modal', name: 'leave-form');
    }

    public function openOvertime(): void
    {
        $this->resetForms();
        $this->start_date = $this->end_date = today()->toDateString();
        $this->start_hour = '17:00';
        $this->end_hour = '19:00';
        $this->dispatch('open-modal', name: 'overtime-form');
    }

    public function submitLeave(): void
    {
        // The service's error keys are the names of this form's fields.
        $request = app(LeaveRequests::class)->submit(Auth::user(), [
            'category' => $this->category,
            'starts_on' => $this->starts_on,
            'ends_on' => $this->ends_on,
            'reason' => $this->reason,
        ], $this->evidence);

        $this->finish('leave', __('Leave request submitted for :count working days.', ['count' => $request->working_days]));
        $this->warnIfUndelivered($request);
    }

    public function submitOvertime(): void
    {
        try {
            $request = app(OvertimeRequests::class)->submit(Auth::user(), [
                'start_at' => $this->start_date.' '.$this->start_hour,
                'end_at' => $this->end_date.' '.$this->end_hour,
                'reason' => $this->overtime_reason,
            ], $this->overtime_evidence);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors[self::OVERTIME_FIELDS[$key] ?? $key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $this->finish('overtime', __('Overtime request submitted for :count hours.', ['count' => $request->hours]));
        $this->warnIfUndelivered($request);
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = Auth::user();
        $type = in_array($this->type, self::TYPES, true) ? $this->type : 'leave';
        $status = RequestStatus::tryFrom($this->statusFilter);

        $query = $type === 'leave'
            ? LeaveRequest::query()->with('category')->orderByDesc('starts_on')
            : OvertimeRequest::query()->orderByDesc('start_at');

        return view('livewire.requests.index', [
            'shownType' => $type,
            'requests' => $query->where('user_id', $user->getKey())
                ->when($status, fn ($query) => $query->where('status', $status->value))
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE),
            'statuses' => RequestStatus::cases(),
            'categories' => LeaveCategory::query()->active()->orderBy('id')->get(),
            'periods' => app(LeaveRequests::class)->slots($user),
            'problem' => app(ApprovalPath::class)->problem($user),
            'maxHours' => OvertimeRequests::MAX_HOURS,
            // The leave date pickers grey out holidays (and weekends): neither is a working day. Validation
            // on the server is unchanged.
            'holidays' => Holiday::query()
                ->whereBetween('date', [today()->toDateString(), today()->addYears(2)->toDateString()])
                ->orderBy('date')
                ->pluck('date')
                ->map(fn ($date) => $date->toDateString())
                ->all(),
        ])->title(__('My requests'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('My requests')],
        ]]);
    }

    private function finish(string $type, string $message): void
    {
        $this->resetForms();
        $this->type = $type;
        $this->statusFilter = '';
        $this->resetPage();

        foreach (['leave-form', 'overtime-form'] as $name) {
            $this->dispatch('close-modal', name: $name);
        }

        $this->dispatch('toast', message: $message, tone: 'success');
    }

    /**
     * The request stands either way; the requester is told when a decider's email did not go out.
     */
    private function warnIfUndelivered(LeaveRequest|OvertimeRequest $request): void
    {
        if (app(RequestNotifications::class)->failures($request) > 0) {
            $this->dispatch('toast', message: __('The request was submitted, but an email to a decider could not be sent. Tell them yourself if it is urgent.'), tone: 'warning');
        }
    }

    private function resetForms(): void
    {
        foreach (self::FILE_PROPERTIES as $property) {
            $this->{$property}?->delete();
        }

        $this->reset(['category', 'starts_on', 'ends_on', 'reason', 'evidence', 'start_date', 'start_hour', 'end_date', 'end_hour', 'overtime_reason', 'overtime_evidence']);
        $this->resetValidation();
    }
}
