<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateUserWithInvitation
{
    public function __construct(private SendInvitation $sendInvitation) {}

    /**
     * Create a PENDING user with a role and an invitation, then email the invitation.
     *
     * The user and the invitation are committed before sending, so a failed
     * transport leaves an account the invitation can still be resent to.
     *
     * @param  array{name: mixed, username: mixed, email: mixed, role: mixed}  $input
     *
     * @throws AuthorizationException
     */
    public function handle(array $input, User $actor): InvitationResult
    {
        if (! $actor->hasPermissionTo(PermissionName::UsersCreate) || ! $actor->hasPermissionTo(PermissionName::UsersInvite)) {
            throw new AuthorizationException;
        }

        $validated = $this->validate($input);

        try {
            [$user, $invitation, $token] = DB::transaction(function () use ($validated, $actor) {
                $user = new User($validated);
                $user->save();

                AuditLog::record(AuditEvent::UserCreated, $actor, $user);

                $user->changeRole(RoleName::from($validated['role']), $actor);

                [$invitation, $token] = AccountInvitation::issueFor($user, $actor);

                AuditLog::record(AuditEvent::InvitationCreated, $actor, $user, ['invitation_id' => $invitation->getKey()]);

                return [$user, $invitation, $token];
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two requests passed validation with the same value. Validating again names the field.
            $this->validate($input);

            throw $exception;
        }

        return $this->sendInvitation->handle($user, $invitation, $token, $actor);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, username: string, email: string, role: string}
     */
    private function validate(array $input): array
    {
        return Validator::make($input, [
            'name' => AccountRules::name(),
            'username' => AccountRules::username(),
            'email' => AccountRules::email(),
            'role' => ['required', Rule::enum(RoleName::class)],
        ])->validate();
    }
}
