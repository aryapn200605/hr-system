<?php

namespace App\Livewire\Profile;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Support\AccountRules;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Only the name changes here. Username and email need a re-authentication, so they have
 * their own controller, and this component has no property or action that could reach them.
 */
class Show extends Component
{
    public string $name = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
    }

    /**
     * Refuse anything but a plain string, which is all the form can send.
     */
    public function updating(string $name, mixed $value): void
    {
        abort_if(str_contains($name, '.') || ! is_string($value), 422);
    }

    /**
     * Save the name, then reload the whole page, because a Livewire update does not render the sidebar.
     */
    public function save(): void
    {
        $validated = $this->validate(['name' => AccountRules::name()]);

        $user = Auth::user();
        $user->name = $validated['name'];

        if ($user->isDirty('name')) {
            $user->save();

            AuditLog::record(AuditEvent::UserUpdated, $user, $user, ['fields' => ['name']]);
        }

        session()->flash('toast', 'Your name has been updated.');

        $this->redirectRoute('profile.show');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.profile.show', ['user' => Auth::user()])
            ->title('My Profile')
            ->layoutData(['breadcrumbs' => [
                ['label' => 'Home', 'href' => route('dashboard')],
                ['label' => 'My Profile'],
            ]]);
    }
}
