<?php

namespace App\Translation;

/**
 * Keys of labels that are picked at runtime: account statuses, role names, login results,
 * device names and leave ledger types. They are written out here so app:translations-missing checks them even if the
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
        // App\Enums\LeaveEntryType::label()
        'Period opened',
        'Adjusted',
        'Closed by reset',
        // App\Enums\RequestStatus::label()
        'Pending approval',
        'Approved',
        'Rejected',
        'Cancelled',
        // App\Models\LeaveCategory::label(), names from Database\Seeders\LeaveCategorySeeder
        'Annual leave',
        'Sick leave',
        'Other leave',
    ];
}
