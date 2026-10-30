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

    public static function activeHrAdminExists(): self
    {
        return new self('An active HR Admin already exists.');
    }

    public static function accountNotPending(): self
    {
        return new self('Only pending accounts can be invited.');
    }
}
