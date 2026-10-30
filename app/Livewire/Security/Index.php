<?php

namespace App\Livewire\Security;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = Auth::user();

        return view('livewire.security.index', [
            'user' => $user,
            'recoveryCodesLeft' => $user->recoveryCodes()->whereNull('used_at')->count(),
        ])->title('Security Settings')->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'Security Settings'],
        ]]);
    }
}
