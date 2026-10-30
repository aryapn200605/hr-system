<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * Keeps links that carry a token out of the session, where the framework would
 * store them as the previous URL. Token routes must name their parameter "token".
 */
class StartSession extends BaseStartSession
{
    /**
     * Store the current URL for the request if necessary.
     *
     * @param  Session  $session
     * @return void
     */
    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->route()?->hasParameter('token')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
