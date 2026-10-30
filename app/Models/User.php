<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_last_timestep'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => UserStatus::Pending->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_enrolled_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Store usernames in lowercase.
     */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower($value));
    }

    /**
     * Store email addresses in lowercase.
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower($value));
    }

    /**
     * Scope the query to users who are active and hold the HR Admin role.
     */
    #[Scope]
    protected function activeHrAdmin(Builder $query): void
    {
        $query->where('status', UserStatus::Active)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', RoleName::HrAdmin->value));
    }

    /**
     * @return HasMany<AccountInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }

    /**
     * @return HasMany<RecoveryCode, $this>
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    /**
     * @return HasMany<LoginHistory, $this>
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * Get the name of the user's role from the database. changeRole() keeps it to one role.
     */
    public function roleName(): ?string
    {
        return $this->roles->first()?->name;
    }

    /**
     * Determine whether the user has finished 2FA enrollment.
     */
    public function hasCompletedTwoFactorEnrollment(): bool
    {
        return $this->two_factor_enrolled_at !== null;
    }

    /**
     * Determine whether the user may use anything behind login. Checked on every request.
     */
    public function canAccessApplication(): bool
    {
        return $this->status === UserStatus::Active && $this->hasCompletedTwoFactorEnrollment();
    }

    /**
     * Store a confirmed 2FA secret and issue recovery codes. Null means the account
     * is no longer eligible, such as when a parallel request finished enrollment first.
     *
     * @return list<string>|null
     */
    public function completeTwoFactorEnrollment(#[SensitiveParameter] string $secret, int $timestep): ?array
    {
        return DB::transaction(function () use ($secret, $timestep) {
            $user = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($user->status !== UserStatus::Active || $user->hasCompletedTwoFactorEnrollment()) {
                return null;
            }

            return $user->enableTwoFactor($secret, $timestep);
        });
    }

    /**
     * Finish an invitation in one transaction: spend it, set the password, enable 2FA and
     * activate the account. Null means the invitation or the account is no longer eligible,
     * such as when a parallel request finished first, and then nothing changes.
     *
     * @return list<string>|null
     */
    public function completeActivation(
        AccountInvitation $invitation,
        #[SensitiveParameter] string $passwordHash,
        #[SensitiveParameter] string $secret,
        int $timestep,
    ): ?array {
        return DB::transaction(function () use ($invitation, $passwordHash, $secret, $timestep) {
            $user = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($user->status !== UserStatus::Pending || ! $invitation->claim()) {
                return null;
            }

            $user->forceFill(['password' => $passwordHash, 'status' => UserStatus::Active]);
            $recoveryCodes = $user->enableTwoFactor($secret, $timestep);

            AuditLog::record(AuditEvent::InvitationConsumed, $user, $user, ['invitation_id' => $invitation->getKey()]);
            AuditLog::record(AuditEvent::PasswordChanged, $user, $user, ['source' => 'activation']);

            return $recoveryCodes;
        });
    }

    /**
     * Replace the password after a password reset and end every session of this user.
     *
     * The database session driver is required for the delete. The auth.session middleware
     * also ends a session that is written back by a request in flight during the delete.
     */
    public function resetPassword(#[SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($password) {
            $this->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $this->getKey())->delete();

            AuditLog::record(AuditEvent::PasswordChanged, $this, $this, ['source' => 'reset']);
        });
    }

    /**
     * Create the first HR Admin. 2FA enrollment stays incomplete until first login.
     *
     * @param  array{name: string, username: string, email: string, password: string}  $attributes
     */
    public static function createFirstHrAdmin(array $attributes): self
    {
        return static::withinHrAdminLock(function () use ($attributes) {
            if (static::query()->activeHrAdmin()->lockForUpdate()->exists()) {
                throw AccountProtectionException::activeHrAdminExists();
            }

            $admin = new static($attributes);
            $admin->status = UserStatus::Active;
            $admin->save();
            $admin->changeRole(RoleName::HrAdmin);

            AuditLog::record(AuditEvent::AdminBootstrapped, subject: $admin);

            return $admin;
        });
    }

    /**
     * Replace the user's role. This is the only supported way to change a user's roles.
     *
     * A null actor means the system itself, such as the bootstrap command.
     */
    public function changeRole(RoleName $role, ?self $actor = null): void
    {
        if ($actor?->is($this)) {
            throw AccountProtectionException::ownRoleChange();
        }

        if ($actor && ! $actor->hasPermissionTo(PermissionName::UsersUpdate)) {
            throw new AuthorizationException;
        }

        static::withinHrAdminLock(function () use ($role, $actor) {
            if ($role !== RoleName::HrAdmin) {
                $this->ensureNotLastActiveHrAdmin();
            }

            $this->roles()->sync([Role::findByName($role->value)->getKey()]);
            $this->unsetRelation('roles');

            AuditLog::record(AuditEvent::UserRoleChanged, $actor, $this, ['role' => $role->value]);
        });
    }

    /**
     * Suspend the user and end everything that could still let them in: sessions, password
     * reset links and open invitations. The last active HR Admin is refused by save().
     *
     * Takes the HR Admin lock before the user row, in the order changeRole() uses, so the two
     * cannot deadlock. A null actor means the system itself.
     */
    public function suspend(?self $actor = null): void
    {
        if ($actor?->is($this)) {
            throw AccountProtectionException::ownSuspension();
        }

        if ($actor && ! $actor->hasPermissionTo(PermissionName::UsersSuspend)) {
            throw new AuthorizationException;
        }

        static::withinHrAdminLock(function () use ($actor) {
            $locked = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($locked->status === UserStatus::Suspended) {
                throw AccountProtectionException::alreadySuspended();
            }

            $locked->status = UserStatus::Suspended;
            $locked->save();

            $this->setRawAttributes($locked->getAttributes(), true);

            AuditLog::record(AuditEvent::UserSuspended, $actor, $this, [
                'sessions_ended' => DB::table('sessions')->where('user_id', $this->getKey())->delete(),
                'invitations_revoked' => AccountInvitation::revokeOpenFor($this),
            ]);

            DB::table('password_reset_tokens')->where('email', $this->email)->delete();
        });
    }

    /**
     * Return a suspended account to ACTIVE. An account suspended before it finished activation
     * has no password, so it can never become active this way.
     *
     * A request in flight during the suspension can write its session back, so sessions are
     * ended again here and an old one does not revive with the account.
     */
    public function reactivate(self $actor): void
    {
        if (! $actor->hasPermissionTo(PermissionName::UsersSuspend)) {
            throw new AuthorizationException;
        }

        static::withinHrAdminLock(function () use ($actor) {
            $locked = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($locked->status !== UserStatus::Suspended) {
                throw AccountProtectionException::notSuspended();
            }

            if ($locked->password === null) {
                throw AccountProtectionException::neverActivated();
            }

            $locked->status = UserStatus::Active;
            $locked->save();

            $this->setRawAttributes($locked->getAttributes(), true);

            DB::table('sessions')->where('user_id', $this->getKey())->delete();

            AuditLog::record(AuditEvent::UserReactivated, $actor, $this);
        });
    }

    /**
     * Roles change through changeRole() only, because Spatie's methods skip the model's guards.
     *
     * @param  mixed  ...$roles
     */
    public function assignRole(...$roles): static
    {
        throw new LogicException('Use User::changeRole() to change roles.');
    }

    /**
     * Roles change through changeRole() only, because Spatie's methods skip the model's guards.
     *
     * @param  mixed  ...$role
     */
    public function removeRole(...$role): static
    {
        throw new LogicException('Use User::changeRole() to change roles.');
    }

    /**
     * Roles change through changeRole() only, because Spatie's methods skip the model's guards.
     *
     * @param  mixed  ...$roles
     */
    public function syncRoles(...$roles): static
    {
        throw new LogicException('Use User::changeRole() to change roles.');
    }

    /**
     * Save the model, refusing any status change that would remove the last active HR Admin.
     *
     * Guarding save() also covers update(), saveQuietly() and saveOrFail().
     *
     * @param  array<string, mixed>  $options
     * @return bool
     */
    public function save(array $options = [])
    {
        if (! $this->exists || ! $this->isDirty('status') || $this->status === UserStatus::Active) {
            return parent::save($options);
        }

        return static::withinHrAdminLock(function () use ($options) {
            $this->ensureNotLastActiveHrAdmin();

            return parent::save($options);
        });
    }

    /**
     * Delete the model, refusing to delete the last active HR Admin.
     *
     * The check runs here because Spatie's deleting listener detaches roles before any listener of ours.
     *
     * @return bool|null
     */
    public function delete()
    {
        return static::withinHrAdminLock(function () {
            $this->ensureNotLastActiveHrAdmin();

            return parent::delete();
        });
    }

    /**
     * Save a confirmed secret and issue recovery codes. The caller holds the user row lock.
     *
     * @return list<string>
     */
    private function enableTwoFactor(#[SensitiveParameter] string $secret, int $timestep): array
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_enrolled_at' => now(),
            'two_factor_last_timestep' => $timestep,
        ])->save();

        $recoveryCodes = RecoveryCode::regenerateFor($this);

        AuditLog::record(AuditEvent::TwoFactorEnrolled, $this, $this);

        return $recoveryCodes;
    }

    /**
     * Run a callback in a transaction holding the HR Admin role row, which serializes
     * every change that could affect the number of active HR Admins.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private static function withinHrAdminLock(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            Role::query()->where('name', RoleName::HrAdmin->value)->lockForUpdate()->first();

            return $callback();
        });
    }

    /**
     * Refuse the change when this user is the only active HR Admin. Reads the database, not this instance.
     */
    private function ensureNotLastActiveHrAdmin(): void
    {
        $activeHrAdmins = static::query()->activeHrAdmin()->lockForUpdate()->pluck('id');

        if ($activeHrAdmins->count() === 1 && $activeHrAdmins->contains($this->getKey())) {
            throw AccountProtectionException::lastActiveHrAdmin();
        }
    }
}
