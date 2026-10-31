<?php

namespace App\Http\Controllers\Requests;

use App\Actions\SubmitLeaveRequest;
use App\Http\Controllers\Controller;
use App\Models\LeaveCategory;
use App\Support\RequestEligibility;
use App\Support\RequestEvidence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A controller and not Livewire: a plain multipart form keeps uploads behind the
 * full login, while Livewire uploads go through a public signed route and a temporary file.
 */
class LeaveRequestController extends Controller
{
    public function create(Request $request): View
    {
        return view('requests.leave-create', [
            'categories' => LeaveCategory::query()->active()->orderBy('id')->get(['id', 'name']),
            'blockedReason' => RequestEligibility::blockedReason($request->user()),
            'accept' => RequestEvidence::accept(),
        ]);
    }

    public function store(Request $request, SubmitLeaveRequest $submit): RedirectResponse
    {
        $leave = $submit->handle(
            $request->user(),
            $request->only(['leave_category_id', 'start_date', 'end_date', 'reason']),
            $request->file('evidence'),
        );

        return redirect()->route('requests.leave.show', $leave->getKey())->with('toast', 'Your leave request was submitted.');
    }
}
