<?php

namespace App\Exceptions;

use DomainException;

class AccountProtectionException extends DomainException
{
    public static function lastActiveHrAdmin(): self
    {
        return new self('The last active HR Admin cannot be suspended, demoted or deleted.');
    }

    public static function ownRoleChange(): self
    {
        return new self('Users cannot change their own role.');
    }

    public static function ownSuspension(): self
    {
        return new self('Users cannot suspend their own account.');
    }

    public static function alreadySuspended(): self
    {
        return new self('This account is already suspended.');
    }

    public static function notSuspended(): self
    {
        return new self('Only suspended accounts can be reactivated.');
    }

    public static function neverActivated(): self
    {
        return new self('This account never finished activation, so it cannot be reactivated.');
    }

    public static function activeHrAdminExists(): self
    {
        return new self('An active HR Admin already exists.');
    }

    public static function accountNotPending(): self
    {
        return new self('Only pending accounts can be invited.');
    }
}
