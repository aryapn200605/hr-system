<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One leave year of one user. Rows are closed, never deleted, so the history stays.
 */
#[Fillable(['start_date', 'end_date', 'entitlement', 'used'])]
class LeaveBalancePeriod extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'entitlement' => 'integer',
            'used' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Get the days left in this period.
     */
    public function remaining(): int
    {
        return $this->entitlement - $this->used;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LeaveBalanceEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LeaveBalanceEntry::class, 'period_id');
    }
}
