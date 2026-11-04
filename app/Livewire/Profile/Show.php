<?php

namespace App\Livewire\Profile;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Language;
use App\Support\AccountRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Only the name and the language change here. Username and email need a re-authentication, so they
 * have their own controller, and this component has no property or action that could reach them.
 */
class Show extends Component
{
    public string $name = '';

    public string $locale = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->locale = Auth::user()->preferredLocale();
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

        session()->flash('toast', __('Your name has been updated.'));

        $this->redirectRoute('profile.show');
    }

    /**
     * Save the interface language, then reload the whole page in it. Only active languages are accepted.
     */
    public function saveLocale(): void
    {
        $validated = $this->validate(['locale' => ['required', 'string', Rule::in(Language::activeCodes()->all())]]);

        $user = Auth::user();
        $user->locale = $validated['locale'];

        if ($user->isDirty('locale')) {
            $user->save();

            AuditLog::record(AuditEvent::UserUpdated, $user, $user, ['fields' => ['locale']]);
        }

        // The confirmation is already in the new language.
        app()->setLocale($user->preferredLocale());
        session()->flash('toast', __('Your language has been changed.'));

        $this->redirectRoute('profile.show');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.profile.show', [
            'user' => Auth::user(),
            'languages' => Language::query()->active()->get(['code', 'native_name']),
        ])
            ->title(__('My Profile'))
            ->layoutData(['breadcrumbs' => [
                ['label' => __('Home'), 'href' => route('dashboard')],
                ['label' => __('My Profile')],
            ]]);
    }
}
