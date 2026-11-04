<?php

namespace App\Http\Controllers;

use App\Services\Requests\Decisions;
use App\Services\Requests\ReviewLock;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lets go of the user's soft review lock when the approval page is closed. The page sends it with
 * navigator.sendBeacon() on pagehide, which a Livewire call could not survive. It only ever clears a
 * lock the user holds, so it needs no other check.
 */
class ReviewLockController extends Controller
{
    public function destroy(Request $request, ReviewLock $lock, string $type, string $id): Response
    {
        $lock->release($request->user(), Decisions::model($type), (int) $id);

        return response()->noContent();
    }
}
