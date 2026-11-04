<?php

namespace App\Http\Controllers\Requests;

use App\Http\Controllers\Controller;
use App\Support\RequestLocks;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page sends this as it closes. If it never arrives, the lock simply expires.
 */
class ReleaseLockController extends Controller
{
    public function __invoke(Request $request, string $kind, int $id): Response
    {
        RequestLocks::release($kind, $id, $request->user());

        return response()->noContent();
    }
}
