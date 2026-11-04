<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Show the interface in the logged-in user's language. Guests, users without a choice and users
 * whose language was deactivated get the default language from config/app.php.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // app.fallback_locale is the default: app()->setLocale() rewrites app.locale on every request.
        $locale = $request->user()?->preferredLocale() ?? config('app.fallback_locale');

        // Carbon follows through its own LocaleUpdated listener, so dates need nothing more.
        app()->setLocale($locale);

        return $next($request);
    }
}
