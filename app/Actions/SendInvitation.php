<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Mail\InvitationMail;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\MailDelivery;
use SensitiveParameter;

class SendInvitation
{
    /**
     * Email a committed invitation and record the outcome. Runs outside any transaction,
     * so a slow transport holds no locks and a failed one rolls nothing back.
     */
    public function handle(
        User $user,
        AccountInvitation $invitation,
        #[SensitiveParameter] string $token,
        User $actor,
        bool $resent = false,
    ): InvitationResult {
        // The invitee has not chosen a language yet, so invitations use the default one.
        $sent = MailDelivery::send($user->email, new InvitationMail($user->name, $token, $resent), config('app.fallback_locale'));

        if ($sent) {
            $invitation->update(['sent_at' => now()]);
        } else {
            AuditLog::record(AuditEvent::InvitationDeliveryFailed, $actor, $user, ['invitation_id' => $invitation->getKey()]);
        }

        return new InvitationResult($user, $invitation, $sent);
    }
}
