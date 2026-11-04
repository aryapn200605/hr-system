<?php

namespace App\Livewire\Security;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class LoginActivity extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.security.login-activity', [
            'logins' => Auth::user()->loginHistories()->latest('occurred_at')->latest('id')->paginate(self::PER_PAGE),
        ])->title('Login history')->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'Security Settings', 'href' => route('security.index')],
            ['label' => 'Login history'],
        ]]);
    }
}
