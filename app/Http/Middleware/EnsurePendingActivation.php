<?php

namespace App\Http\Middleware;

use App\Support\PendingActivation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a request only with a valid pending activation, so a revoked or expired invitation stops at the next step.
 */
class EnsurePendingActivation
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $invitation = PendingActivation::invitation($request);

        if (! $invitation) {
            PendingActivation::clear($request);

            return response()->view('auth.activation-invalid', status: 410);
        }

        $request->attributes->set('pending_invitation', $invitation);

        return $next($request);
    }
}
