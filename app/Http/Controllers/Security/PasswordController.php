<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Support\AccountRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PasswordController extends Controller
{
    /**
     * Show the form.
     */
    public function edit(): View
    {
        return view('security.password');
    }

    /**
     * Change the password. This session stays logged in: auth.session stores
     * the new password hash in it on the way out, and every other session ends.
     * The re-authentication proof dies with the old password hash.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['password' => AccountRules::password()]);

        $user = $request->user();

        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors(['password' => __('The new password must be different from the current one.')]);
        }

        $user->changePassword($validated['password'], $request->session()->getId());

        $request->session()->regenerate(true);

        return redirect()->route('security.index')->with('toast', __('Your password has been changed. Other sessions have ended.'));
    }
}
