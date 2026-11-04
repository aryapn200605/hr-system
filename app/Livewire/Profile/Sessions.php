<?php

namespace App\Livewire\Profile;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Support\ActiveSessions;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Sessions extends Component
{
    /**
     * End one other session. The handle is only looked up among the user's own other sessions.
     */
    public function terminate(mixed $handle): void
    {
        abort_unless(is_string($handle), 422);

        $user = Auth::user();

        if (! ActiveSessions::endOne($user, session()->getId(), $handle)) {
            $this->dispatch('toast', message: __('That session has already ended.'), tone: 'info');

            return;
        }

        AuditLog::record(AuditEvent::SessionTerminated, $user, $user, ['scope' => 'single', 'sessions_ended' => 1]);

        $this->dispatch('toast', message: __('The session has ended.'));
    }

    /**
     * End every session of this user except the current one.
     */
    public function terminateOthers(): void
    {
        $user = Auth::user();

        $ended = $user->endOtherSessions(session()->getId());

        if ($ended === 0) {
            $this->dispatch('toast', message: __('There are no other sessions.'), tone: 'info');

            return;
        }

        AuditLog::record(AuditEvent::SessionTerminated, $user, $user, ['scope' => 'others', 'sessions_ended' => $ended]);

        $this->dispatch('toast', message: __('Other sessions have ended.'));
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.profile.sessions', [
            'sessions' => ActiveSessions::listFor(Auth::user(), session()->getId()),
        ])->title(__('Active sessions'))->layoutData(['breadcrumbs' => [
            ['label' => __('Home'), 'href' => route('dashboard')],
            ['label' => __('My Profile'), 'href' => route('profile.show')],
            ['label' => __('Active sessions')],
        ]]);
    }
}
