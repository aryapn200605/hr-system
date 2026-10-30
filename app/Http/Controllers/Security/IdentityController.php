<?php

namespace App\Http\Controllers\Security;

use App\Actions\UpdateOwnIdentity;
use App\Http\Controllers\Controller;
use App\Support\Reauthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IdentityController extends Controller
{
    /**
     * Show the form.
     */
    public function edit(Request $request): View
    {
        return view('security.identity', ['user' => $request->user()]);
    }

    /**
     * Change the user's own username or email. An unchanged form keeps the proof.
     */
    public function update(Request $request, UpdateOwnIdentity $action): RedirectResponse
    {
        $changed = $action->handle($request->user(), $request->only(['username', 'email', 'email_confirmation']));

        if ($changed === []) {
            return redirect()->route('profile.show')->with('toast', __('Nothing changed.'));
        }

        Reauthentication::spend($request);

        return redirect()->route('profile.show')->with('toast', __('Your account details have been updated.'));
    }
}
