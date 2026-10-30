<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateOwnIdentity
{
    /**
     * Change the user's own username or email and return the names of the changed fields.
     *
     * The caller holds a re-authentication proof, which stands in for a confirmation email, as
     * email verification is out of scope. Reset links for both addresses are deleted: the old one's
     * belong to an address this account has left, and a new one's may predate this account taking it.
     *
     * @param  array{username: mixed, email: mixed, email_confirmation: mixed}  $input
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): array
    {
        try {
            return DB::transaction(function () use ($user, $input) {
                $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

                $locked->fill($this->validate($input, $locked));

                $changed = array_keys($locked->getDirty());

                if ($changed === []) {
                    return [];
                }

                $oldEmail = $locked->getOriginal('email');

                $locked->save();

                if ($locked->email !== $oldEmail) {
                    DB::table('password_reset_tokens')->whereIn('email', [$oldEmail, $locked->email])->delete();
                }

                AuditLog::record(AuditEvent::UserUpdated, $user, $locked, ['fields' => $changed]);

                return $changed;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two accounts passed the unique rule at once, and the index stopped the second.
            $field = str_contains($exception->getMessage(), 'users_username_unique') ? 'username' : 'email';

            throw ValidationException::withMessages([$field => __('validation.unique', ['attribute' => $field])]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{username: string, email: string}
     */
    private function validate(array $input, User $user): array
    {
        return Validator::make($input, [
            'username' => AccountRules::username($user),
            'email' => [...AccountRules::email($user), 'confirmed'],
        ])->validate();
    }
}
