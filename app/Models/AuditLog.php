<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

#[Fillable(['actor_id', 'event', 'subject_type', 'subject_id', 'ip_address', 'user_agent', 'metadata'])]
class AuditLog extends Model
{
    /**
     * Audit records are never updated.
     */
    public const UPDATED_AT = null;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        $refuse = fn () => throw new LogicException('Audit records cannot be modified.');

        static::updating($refuse);
        static::deleting($refuse);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Record an event. A null actor means the system itself.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(AuditEvent $event, ?User $actor = null, ?Model $subject = null, array $metadata = []): self
    {
        $request = app()->runningInConsole() ? null : request();

        return static::create([
            'actor_id' => $actor?->getKey(),
            'event' => $event->value,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata ?: null,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
