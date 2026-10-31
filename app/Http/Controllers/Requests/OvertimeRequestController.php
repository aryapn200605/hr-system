<?php

namespace App\Http\Controllers\Requests;

use App\Actions\SubmitOvertimeRequest;
use App\Http\Controllers\Controller;
use App\Support\RequestEligibility;
use App\Support\RequestEvidence;
use App\Support\RequestRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A controller and not Livewire, for the same reason as LeaveRequestController.
 */
class OvertimeRequestController extends Controller
{
    public function create(Request $request): View
    {
        return view('requests.overtime-create', [
            'blockedReason' => RequestEligibility::blockedReason($request->user()),
            'accept' => RequestEvidence::accept(),
            'maxHours' => RequestRules::MAX_OVERTIME_HOURS,
            'earliest' => today()->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(Request $request, SubmitOvertimeRequest $submit): RedirectResponse
    {
        $overtime = $submit->handle(
            $request->user(),
            $request->only(['start_at', 'end_at', 'reason']),
            $request->file('evidence'),
        );

        return redirect()->route('requests.overtime.show', $overtime->getKey())->with('toast', 'Your overtime request was submitted.');
    }
}
