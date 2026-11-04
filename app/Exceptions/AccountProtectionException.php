<?php

namespace App\Exceptions;

use DomainException;

class AccountProtectionException extends DomainException
{
    public static function lastActiveHrAdmin(): self
    {
        return new self(__('The last active HR Admin cannot be suspended, demoted or deleted.'));
    }

    public static function ownRoleChange(): self
    {
        return new self(__('Users cannot change their own role.'));
    }

    public static function ownSuspension(): self
    {
        return new self(__('Users cannot suspend their own account.'));
    }

    public static function alreadySuspended(): self
    {
        return new self(__('This account is already suspended.'));
    }

    public static function notSuspended(): self
    {
        return new self(__('Only suspended accounts can be reactivated.'));
    }

    public static function neverActivated(): self
    {
        return new self(__('This account never finished activation, so it cannot be reactivated.'));
    }

    public static function activeHrAdminExists(): self
    {
        return new self(__('An active HR Admin already exists.'));
    }

    public static function accountNotPending(): self
    {
        return new self(__('Only pending accounts can be invited.'));
    }
}
