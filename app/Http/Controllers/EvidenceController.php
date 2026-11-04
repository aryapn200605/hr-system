<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Policies\RequestPolicy;
use App\Services\Requests\Evidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read request evidence. Anyone who may not see the request gets a 404, the same as
 * for a request that does not exist, so nothing about other people's requests leaks.
 */
class EvidenceController extends Controller
{
    public function show(Request $request, RequestPolicy $policy, string $type, string $id): StreamedResponse
    {
        $model = match ($type) {
            'leave' => LeaveRequest::class,
            'overtime' => OvertimeRequest::class,
        };

        $record = $model::query()->findOrFail($id);

        abort_unless($policy->downloadEvidence($request->user(), $record), 404);

        return Storage::disk(Evidence::DISK)->download($record->evidence_path, $record->evidence_name, [
            'Content-Type' => $record->evidence_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
