<?php

namespace App\Models;

use App\Enums\AuditEvent;
use App\Enums\LoginResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

#[Fillable(['user_id', 'result', 'ip_address', 'user_agent', 'occurred_at'])]
#[WithoutTimestamps]
class LoginHistory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Record a full login in the history and the audit log.
     */
    public static function recordSuccess(Request $request, User $user, string $method): self
    {
        AuditLog::record(AuditEvent::LoginSucceeded, $user, $user, ['method' => $method]);

        return static::write($request, LoginResult::Success, $user);
    }

    /**
     * Record a failed attempt in the history and the audit log. The typed identifier is never stored.
     */
    public static function recordFailure(Request $request, LoginResult $result, ?User $user = null): self
    {
        AuditLog::record(AuditEvent::LoginFailed, subject: $user, metadata: ['result' => $result->value]);

        return static::write($request, $result, $user);
    }

    /**
     * Write a history row.
     */
    private static function write(Request $request, LoginResult $result, ?User $user): self
    {
        return static::create([
            'user_id' => $user?->getKey(),
            'result' => $result->value,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
