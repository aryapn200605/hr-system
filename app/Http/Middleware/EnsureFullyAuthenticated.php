<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only for a logged-in, active user with 2FA enrolled.
 *
 * The guard reloads the user from the database on every request, so a suspension takes effect at once.
 */
class EnsureFullyAuthenticated
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->canAccessApplication()) {
            return $next($request);
        }

        if ($user) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            abort(401);
        }

        return redirect()->route('login');
    }
}
