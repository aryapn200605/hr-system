<?php

namespace App\Livewire;

use Livewire\Component;

/**
 * Placeholder landing page for the login flow. The real dashboard comes in Phase 4.
 */
class Dashboard extends Component
{
    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.dashboard');
    }
}
