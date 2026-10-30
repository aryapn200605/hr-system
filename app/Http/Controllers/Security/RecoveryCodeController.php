<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Support\Reauthentication;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class RecoveryCodeController extends Controller
{
    /**
     * Explain what regenerating does.
     */
    public function create(): View
    {
        return view('security.recovery-codes');
    }

    /**
     * Replace the recovery codes and show the new ones.
     *
     * The codes go straight into this response and never into the session or a Livewire
     * snapshot, so they appear exactly once. Spending the proof means a reloaded POST
     * goes back to the confirmation page and cannot void the codes on the screen.
     */
    public function store(Request $request): Response
    {
        $recoveryCodes = $request->user()->regenerateRecoveryCodes();

        Reauthentication::spend($request);

        // Set here because the cache.headers middleware only touches GET and HEAD responses.
        return response()
            ->view('auth.recovery-codes', [
                'recoveryCodes' => $recoveryCodes,
                'notice' => 'Your previous recovery codes no longer work.',
                'continueUrl' => route('security.index'),
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
