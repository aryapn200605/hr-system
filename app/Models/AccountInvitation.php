<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

#[Fillable(['user_id', 'invited_by', 'token_hash', 'expires_at', 'sent_at', 'accepted_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class AccountInvitation extends Model
{
    /**
     * Revoke the user's open invitations and issue a new one. The plain token
     * exists only in this return value, on its way into the email.
     *
     * Locks the user row first, as User::completeActivation() does, so resending
     * and activating serialize and cannot deadlock.
     *
     * @return array{0: self, 1: string}
     */
    public static function issueFor(User $user, User $inviter): array
    {
        return DB::transaction(function () use ($user, $inviter) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($locked->status !== UserStatus::Pending) {
                throw AccountProtectionException::accountNotPending();
            }

            static::query()
                ->where('user_id', $locked->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $token = bin2hex(random_bytes(32));

            $invitation = static::create([
                'user_id' => $locked->getKey(),
                'invited_by' => $inviter->getKey(),
                'token_hash' => static::hashFor($token),
                'expires_at' => now()->addMinutes(config('auth.invitations.expire')),
            ]);

            return [$invitation, $token];
        });
    }

    /**
     * Find a usable invitation by its plain token. Unknown, used, revoked and expired tokens all give null.
     */
    public static function findOpenByToken(#[SensitiveParameter] string $token): ?self
    {
        return static::query()
            ->open()
            ->where('token_hash', static::hashFor($token))
            ->first();
    }

    /**
     * Hash a token. It carries 256 random bits, so SHA-256 is enough and allows an indexed lookup.
     */
    public static function hashFor(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Spend the invitation. The single conditional update lets only one of two concurrent requests
     * succeed, and it refuses an invitation that was revoked or expired since it was read.
     */
    public function claim(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->open()
            ->update(['accepted_at' => now()]) === 1;
    }

    /**
     * Scope the query to invitations that can still be used.
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
