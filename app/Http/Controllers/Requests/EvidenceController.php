<?php

namespace App\Http\Controllers\Requests;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Support\RequestEvidence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way a file leaves the disk. IDs stay plain route parameters, and an unknown one
 * gets the same 403 as a forbidden one, so nobody can find out which requests exist.
 */
class EvidenceController extends Controller
{
    public function leave(Request $request, string $id): StreamedResponse
    {
        return $this->download($request, LeaveRequest::query()->find($id));
    }

    public function overtime(Request $request, string $id): StreamedResponse
    {
        return $this->download($request, OvertimeRequest::query()->find($id));
    }

    private function download(Request $request, LeaveRequest|OvertimeRequest|null $model): StreamedResponse
    {
        abort_unless($model && $request->user()->can('viewEvidence', $model), 403);
        abort_unless($model->hasEvidence() && RequestEvidence::disk()->exists($model->evidence_path), 404);

        // Always an attachment, and a file that is more than it claims cannot run in this origin.
        return RequestEvidence::disk()->download($model->evidence_path, RequestEvidence::downloadName($model), [
            'Content-Type' => $model->evidence_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
