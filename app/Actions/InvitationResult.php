<?php

namespace App\Actions;

use App\Models\AccountInvitation;
use App\Models\User;

/**
 * The outcome of creating or resending an invitation. $sent is false when the transport
 * refused the email. The invitation stays valid then, and it can be resent.
 */
final readonly class InvitationResult
{
    public function __construct(
        public User $user,
        public AccountInvitation $invitation,
        public bool $sent,
    ) {}
}
