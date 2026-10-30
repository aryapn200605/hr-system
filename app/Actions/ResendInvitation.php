<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuthThrottle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResendInvitation
{
    public function __construct(private SendInvitation $sendInvitation) {}

    /**
     * Revoke the user's open invitations, issue a new one and email it.
     *
     * The old link dies before sending, so after a failed transport no link
     * is valid in the user's inbox until the next resend. The result says so.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, User $actor): InvitationResult
    {
        if (! $actor->hasPermissionTo(PermissionName::UsersInvite)) {
            throw new AuthorizationException;
        }

        $key = AuthThrottle::invitationResendKey($user);

        if (AuthThrottle::resendExceeded($key)) {
            throw ValidationException::withMessages(['invitation' => AuthThrottle::message($key)]);
        }

        [$invitation, $token] = DB::transaction(function () use ($user, $actor) {
            [$invitation, $token] = AccountInvitation::issueFor($user, $actor);

            AuditLog::record(AuditEvent::InvitationResent, $actor, $user, ['invitation_id' => $invitation->getKey()]);

            return [$invitation, $token];
        });

        return $this->sendInvitation->handle($user, $invitation, $token, $actor, resent: true);
    }
}
