<?php

namespace App\Models;

use App\Enums\LeaveEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The ledger of a leave period: who changed what, when, why, and the values on both sides.
 */
#[Fillable(['actor_id', 'type', 'entitlement_before', 'entitlement_after', 'used_before', 'used_after', 'reason'])]
class LeaveBalanceEntry extends Model
{
    /**
     * Ledger entries are never updated.
     */
    public const UPDATED_AT = null;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        $refuse = fn () => throw new LogicException('Ledger entries cannot be modified.');

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
        ];
    }

    /**
     * @return BelongsTo<LeaveBalancePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(LeaveBalancePeriod::class, 'period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
