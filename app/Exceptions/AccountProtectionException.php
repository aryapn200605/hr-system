<?php

namespace App\Exceptions;

use App\Models\User;
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

    public static function alreadySupervisor(): self
    {
        return new self('This person is already a supervisor of this user.');
    }

    public static function notSupervisor(): self
    {
        return new self('This person is no longer a supervisor of this user.');
    }

    public static function sameSupervisor(): self
    {
        return new self('Choose two different supervisors.');
    }

    public static function nothingToMove(User $from): self
    {
        return new self('Nothing was moved: the chosen employees must all be subordinates of '.$from->name.'.');
    }

    public static function alreadyResetToday(): self
    {
        return new self('This balance already started a new period today, so there is nothing to reset.');
    }

    public static function noOpenPeriod(): self
    {
        return new self('This user has no open leave period yet.');
    }
}
