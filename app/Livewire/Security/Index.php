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
        ])->title(__('Security Settings'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('Security Settings')],
        ]]);
    }
}
