<?php

namespace App\Http\Middleware;

use App\Support\Reauthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a sensitive request to the confirmation page unless this session holds a live proof.
 */
class EnsureReauthenticated
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $returnTo): Response
    {
        if (Reauthentication::isValid($request, $request->user())) {
            return $next($request);
        }

        Reauthentication::intend($request, $returnTo);

        return redirect()->route('security.confirm');
    }
}
