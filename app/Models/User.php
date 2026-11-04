<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use App\Exceptions\SupervisorGuardException;
use App\Services\LeaveBalance;
use App\Services\SupervisorGuard;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The language for this user's pages and emails: their choice while it is an active language,
     * otherwise the default. `locale` is set only through My Profile and is not mass assignable.
     */
    public function preferredLocale(): string
    {
        return Language::isActive($this->locale) ? $this->locale : config('app.fallback_locale');
    }

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => UserStatus::Pending->value,
    ];

    /**
     * Every new account gets its leave anchor date (today in WIB unless set) and its first
     * leave period at once, in the transaction that creates it, without waiting for the daily job.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->leave_anchor_date ??= today();
        });

        static::created(function (self $user) {
            app(LeaveBalance::class)->sync($user);
        });
    }

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
            'join_date' => 'date',
            'leave_anchor_date' => 'date',
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
     * Scope the query to users who are active and hold the Approver role: the only users who can supervise.
     */
    #[Scope]
    protected function activeApprover(Builder $query): void
    {
        $query->where('status', UserStatus::Active)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', RoleName::Approver->value));
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Everyone linked as this user's supervisor, whatever their status. Only active Approvers count.
     *
     * @return BelongsToMany<User, $this>
     */
    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'supervisor_user', 'subordinate_id', 'supervisor_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function subordinates(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'supervisor_user', 'supervisor_id', 'subordinate_id');
    }

    /**
     * @return HasMany<LeavePeriod, $this>
     */
    public function leavePeriods(): HasMany
    {
        return $this->hasMany(LeavePeriod::class);
    }

    /**
     * Whether this user's role needs a supervisor to submit requests. Approvers go to any HR Admin instead.
     */
    public function needsSupervisor(): bool
    {
        return in_array($this->roleName(), [RoleName::Employee->value, RoleName::HrAdmin->value], true);
    }

    /**
     * Whether this user's requests go to any active HR Admin instead of a supervisor (DECISIONS.md section 3).
     */
    public function requestsGoToHrAdmin(): bool
    {
        return $this->roleName() === RoleName::Approver->value;
    }

    /**
     * Whether this user holds the Approver role, the role that supervises (read from the loaded roles).
     */
    public function isApprover(): bool
    {
        return $this->roleName() === RoleName::Approver->value;
    }

    /**
     * Whether this user holds the HR Admin role (read from the loaded roles).
     */
    public function isHrAdmin(): bool
    {
        return $this->roleName() === RoleName::HrAdmin->value;
    }

    /**
     * Scope the query to users whose requests go to a supervisor: Employee and HR Admin.
     */
    #[Scope]
    protected function requestingFromSupervisor(Builder $query): void
    {
        $query->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', [RoleName::Employee->value, RoleName::HrAdmin->value]));
    }

    /**
     * Scope the query to users whose requests go to any active HR Admin: Approvers.
     */
    #[Scope]
    protected function requestingFromHrAdmin(Builder $query): void
    {
        $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', RoleName::Approver->value));
    }

    /**
     * Whether an active Approver supervises this user now.
     */
    public function hasActiveSupervisor(): bool
    {
        return app(SupervisorGuard::class)->activeSupervisors()->where('su.subordinate_id', $this->getKey())->exists();
    }

    /**
     * The "No supervisor" marker: an Employee or HR Admin who is not suspended and has no active supervisor.
     * Approvers never get it, because their requests go to any HR Admin.
     */
    public function lacksSupervisor(): bool
    {
        return $this->needsSupervisor() && $this->status !== UserStatus::Suspended && ! $this->hasActiveSupervisor();
    }

    /**
     * Scope the query to users who would show the "No supervisor" marker.
     */
    #[Scope]
    protected function lackingSupervisor(Builder $query): void
    {
        $query->where('users.status', '!=', UserStatus::Suspended->value)
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', [RoleName::Employee->value, RoleName::HrAdmin->value]))
            ->whereNotExists(fn (QueryBuilder $links) => $links->selectRaw('1')
                ->fromSub(app(SupervisorGuard::class)->activeSupervisors()->select('su.subordinate_id'), 'active_links')
                ->whereColumn('active_links.subordinate_id', 'users.id'));
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
     * Replace the password from Security Settings and end every other session of this user.
     *
     * Reset links die too: one requested before this change must not undo it. The caller
     * updates the password hash of its own session, which auth.session then accepts.
     */
    public function changePassword(#[SensitiveParameter] string $password, string $currentSessionId): void
    {
        DB::transaction(function () use ($password, $currentSessionId) {
            $locked = static::query()->lockForUpdate()->findOrFail($this->getKey());

            $this->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('password_reset_tokens')->where('email', $locked->email)->delete();

            AuditLog::record(AuditEvent::PasswordChanged, $this, $this, [
                'source' => 'security_settings',
                'sessions_ended' => $this->endOtherSessions($currentSessionId),
            ]);
        });
    }

    /**
     * Replace the 2FA secret with a confirmed new one and issue new recovery codes, all or
     * nothing. The old codes go too, as they may have left with the old device, and so do
     * the other sessions. Null means the account is no longer eligible, and nothing changes.
     *
     * @return list<string>|null
     */
    public function reconfigureTwoFactor(#[SensitiveParameter] string $secret, int $timestep, string $currentSessionId): ?array
    {
        return DB::transaction(function () use ($secret, $timestep, $currentSessionId) {
            $user = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $user->canAccessApplication()) {
                return null;
            }

            return $user->enableTwoFactor($secret, $timestep, AuditEvent::TwoFactorReconfigured, [
                'sessions_ended' => $this->endOtherSessions($currentSessionId),
            ]);
        });
    }

    /**
     * Replace the recovery codes. The row lock stops two requests from mixing their sets.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(): array
    {
        return DB::transaction(function () {
            static::query()->lockForUpdate()->findOrFail($this->getKey());

            $recoveryCodes = RecoveryCode::regenerateFor($this);

            AuditLog::record(AuditEvent::RecoveryCodesRegenerated, $this, $this);

            return $recoveryCodes;
        });
    }

    /**
     * Take 2FA away, for the reset command only. The next login forces a new enrollment.
     * False means the account has no 2FA to reset, such as when a parallel reset came first.
     */
    public function resetTwoFactor(): bool
    {
        return DB::transaction(function () {
            $user = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $user->hasCompletedTwoFactorEnrollment()) {
                return false;
            }

            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_enrolled_at' => null,
                'two_factor_last_timestep' => null,
            ])->save();

            $user->recoveryCodes()->delete();

            AuditLog::record(AuditEvent::TwoFactorReset, subject: $user, metadata: [
                'sessions_ended' => DB::table('sessions')->where('user_id', $user->getKey())->delete(),
            ]);

            return true;
        });
    }

    /**
     * End every session of this user except one, and return how many ended.
     *
     * A request in flight on an ended session cannot bring it back: the handler already
     * saw the row, so it only updates, which changes nothing once the row is gone.
     */
    public function endOtherSessions(string $currentSessionId): int
    {
        return DB::table('sessions')
            ->where('user_id', $this->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    /**
     * Create the first HR Admin. 2FA enrollment stays incomplete until first login.
     *
     * @param  array{name: string, username: string, email: string, password: string}  $attributes
     */
    public static function createFirstHrAdmin(array $attributes): self
    {
        return static::withinAccountLock(function () use ($attributes) {
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

        app(SupervisorGuard::class)->auditing('role_change', $actor, $this, fn () => static::withinAccountLock(function () use ($role, $actor) {
            if ($role !== RoleName::HrAdmin) {
                $this->ensureNotLastActiveHrAdmin();
            }

            $wasApprover = $this->roles()->where('name', RoleName::Approver->value)->exists();
            $metadata = ['role' => $role->value];

            app(SupervisorGuard::class)->check([$this->getKey()], function () use ($role, $wasApprover, &$metadata) {
                $this->roles()->sync([Role::findByName($role->value)->getKey()]);
                $this->unsetRelation('roles');

                // Only Approvers can supervise. Losing the role ends this user's links as a supervisor for good;
                // a suspension keeps them instead (README, "Supervisors").
                if ($wasApprover && $role !== RoleName::Approver) {
                    $metadata['supervisor_links_removed'] = DB::table('supervisor_user')->where('supervisor_id', $this->getKey())->delete();
                }
            });

            AuditLog::record(AuditEvent::UserRoleChanged, $actor, $this, $metadata);
        }));
    }

    /**
     * Suspend the user and end everything that could still let them in: sessions, password
     * reset links and open invitations. The last active HR Admin is refused by save().
     *
     * Takes the account lock before the user row, in the order changeRole() uses, so the two
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

        app(SupervisorGuard::class)->auditing('suspend', $actor, $this, fn () => static::withinAccountLock(function () use ($actor) {
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
        }));
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

        static::withinAccountLock(function () use ($actor) {
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

        $original = $this->original;

        try {
            return app(SupervisorGuard::class)->auditing('status_change', null, $this, fn () => static::withinAccountLock(function () use ($options) {
                $this->ensureNotLastActiveHrAdmin();

                // A user who stops being active stops counting as a supervisor. The links stay and count again on reactivation.
                return app(SupervisorGuard::class)->check([$this->getKey()], fn () => parent::save($options));
            }));
        } catch (SupervisorGuardException $exception) {
            // The row was rolled back, so this instance must not believe the new status was saved.
            $this->original = $original;

            throw $exception;
        }
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
        try {
            return app(SupervisorGuard::class)->auditing('delete', null, $this, fn () => static::withinAccountLock(function () {
                $this->ensureNotLastActiveHrAdmin();

                // The supervisor links go with the row (foreign key cascade), inside this transaction.
                return app(SupervisorGuard::class)->check([$this->getKey()], fn () => parent::delete());
            }));
        } catch (SupervisorGuardException $exception) {
            // The row was rolled back, so this instance still exists.
            $this->exists = true;

            throw $exception;
        }
    }

    /**
     * Save a confirmed secret and issue recovery codes. The caller holds the user row lock.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function enableTwoFactor(
        #[SensitiveParameter] string $secret,
        int $timestep,
        AuditEvent $event = AuditEvent::TwoFactorEnrolled,
        array $metadata = [],
    ): array {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_enrolled_at' => now(),
            'two_factor_last_timestep' => $timestep,
        ])->save();

        $recoveryCodes = RecoveryCode::regenerateFor($this);

        AuditLog::record($event, $this, $this, $metadata);

        return $recoveryCodes;
    }

    /**
     * Run a callback in a transaction holding the HR Admin role row. It serializes every change that
     * could affect the number of active HR Admins, and every change to who supervises whom or who can
     * supervise (App\Services\SupervisorGuard): statuses, roles, deletes and supervisor links.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withinAccountLock(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            Role::query()->where('name', RoleName::HrAdmin->value)->lockForUpdate()->first();

            return $callback();
        });
    }

    /**
     * Hold the same row in shared mode for the rest of the caller's transaction. Decisions on requests
     * take it first, so who may decide cannot change while one runs, yet decisions do not wait for each other.
     */
    public static function shareAccountLock(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The shared account lock needs a transaction.');
        }

        Role::query()->where('name', RoleName::HrAdmin->value)->sharedLock()->first();
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
