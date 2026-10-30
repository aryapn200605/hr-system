<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The single definition of account validation, shared by commands and forms.
 */
class AccountRules
{
    /**
     * @return array<int, mixed>
     */
    public static function name(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Letters, digits, dots, underscores and dashes only, so "@" always marks an email at login.
     *
     * @return array<int, mixed>
     */
    public static function username(?User $ignore = null): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:50',
            'regex:/\A[A-Za-z0-9._-]+\z/',
            Rule::unique('users', 'username')->ignore($ignore),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function email(?User $ignore = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            Rule::unique('users', 'email')->ignore($ignore),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function password(): array
    {
        return ['required', 'string', Password::defaults(), 'confirmed'];
    }
}
