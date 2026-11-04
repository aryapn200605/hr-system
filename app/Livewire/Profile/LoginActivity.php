<?php

namespace App\Livewire\Profile;

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
        return view('livewire.profile.login-activity', [
            'logins' => Auth::user()->loginHistories()->latest('occurred_at')->latest('id')->paginate(self::PER_PAGE),
        ])->title(__('Login history'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('My Profile'), 'href' => route('profile.show')],
            ['label' => __('Login history')],
        ]]);
    }
}
