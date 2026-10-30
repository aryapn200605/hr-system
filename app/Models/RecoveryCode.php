<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;

#[Fillable(['user_id', 'code_hash', 'used_at'])]
#[Hidden(['code_hash'])]
class RecoveryCode extends Model
{
    /**
     * Codes issued per user.
     */
    private const COUNT = 10;

    /**
     * 32 unambiguous characters, so each one carries 5 bits and a code carries 80.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 16;

    /**
     * Separates this key from every other use of APP_KEY.
     */
    private const KEY_LABEL = 'hr-system.recovery-codes.v1';

    /**
     * Replace the user's codes and return the new ones. This is the only time they exist in plain text.
     *
     * @return list<string>
     */
    public static function regenerateFor(User $user): array
    {
        $user->recoveryCodes()->delete();

        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = static::generateCode();
            $user->recoveryCodes()->create(['code_hash' => static::hashFor($user, $codes[$i])]);
        }

        return $codes;
    }

    /**
     * Spend a code. The single conditional update lets only one of two concurrent requests succeed.
     */
    public static function consume(User $user, #[SensitiveParameter] string $code): bool
    {
        return static::query()
            ->where('user_id', $user->getKey())
            ->where('code_hash', static::hashFor($user, $code))
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    /**
     * Hash a code for one user, ignoring case and separators.
     *
     * Codes are random with 80 bits of entropy, so a fast keyed hash is enough and allows an
     * indexed lookup. The key is derived from APP_KEY, so rotating APP_KEY voids every code.
     */
    public static function hashFor(User $user, #[SensitiveParameter] string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $key = hash_hkdf('sha256', Crypt::getKey(), 32, self::KEY_LABEL);

        return hash_hmac('sha256', $user->getKey().':'.$normalized, $key);
    }

    /**
     * Generate one code in groups of four, such as ABCD-EFGH-JKLM-NPQR.
     */
    private static function generateCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($code, 4));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
