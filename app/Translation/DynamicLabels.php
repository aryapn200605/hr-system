<?php

namespace App\Translation;

/**
 * Keys of labels that are picked at runtime: account statuses, role names, login results and
 * device names. They are written out here so app:translations-missing checks them even if the
 * code that uses them stops naming them as literals. DynamicLabelsTest keeps this list complete.
 */
class DynamicLabels
{
    public const KEYS = [
        // App\Enums\UserStatus::label()
        'Pending',
        'Active',
        'Suspended',
        // App\Enums\RoleName::label()
        'HR Admin',
        'Approver',
        'Employee',
        // App\Enums\LoginResult::label()
        'Successful',
        'Wrong password',
        'Account not active',
        'Wrong authenticator code',
        'Wrong recovery code',
        // App\Support\UserAgentLabel::for()
        ':browser on :system',
        'Unknown device',
    ];
}
