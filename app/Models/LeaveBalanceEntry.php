<?php

namespace App\Models;

use App\Enums\LeaveEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A row of the leave balance ledger. The ledger is append-only: rows are never changed or removed.
 */
#[Fillable(['leave_period_id', 'user_id', 'actor_id', 'type', 'change', 'before', 'after', 'reason'])]
class LeaveBalanceEntry extends Model
{
    public const UPDATED_AT = null;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        $refuse = fn () => throw new LogicException('Leave ledger rows cannot be modified.');

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
            'type' => LeaveEntryType::class,
            'change' => 'integer',
            'before' => 'integer',
            'after' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LeavePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(LeavePeriod::class, 'leave_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
