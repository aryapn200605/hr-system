<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class UpdateUser
{
    /**
     * Change a user's name, username or email and return how many invitations this revoked.
     *
     * A pending account's invitation was sent to the old identity, so a changed username or email
     * revokes it and the admin resends. The user row lock serializes this with activation.
     *
     * @param  array{name: mixed, username: mixed, email: mixed}  $input
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, array $input, User $actor): int
    {
        if (Gate::forUser($actor)->denies('update', $user)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($user, $input, $actor) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $locked->fill($this->validate($input, $locked));

            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return 0;
            }

            $identityChanged = array_intersect($changed, ['username', 'email']) !== [];

            if ($identityChanged && Gate::forUser($actor)->denies('updateIdentity', $locked)) {
                throw new AuthorizationException;
            }

            $oldEmail = $locked->getOriginal('email');

            $locked->save();

            $revoked = $identityChanged && $locked->status === UserStatus::Pending
                ? AccountInvitation::revokeOpenFor($locked)
                : 0;

            // A reset link belongs to the address it was sent to.
            if ($locked->email !== $oldEmail) {
                DB::table('password_reset_tokens')->where('email', $oldEmail)->delete();
            }

            AuditLog::record(AuditEvent::UserUpdated, $actor, $locked, [
                'fields' => $changed,
                'invitations_revoked' => $revoked,
            ]);

            return $revoked;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, username: string, email: string}
     */
    private function validate(array $input, User $user): array
    {
        return Validator::make($input, [
            'name' => AccountRules::name(),
            'username' => AccountRules::username($user),
            'email' => AccountRules::email($user),
        ])->validate();
    }
}
