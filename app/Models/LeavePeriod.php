<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One leave year of one user, from starts_on to ends_on inclusive. Changed only by App\Services\LeaveBalance.
 */
#[Fillable(['user_id', 'starts_on', 'ends_on', 'entitlement', 'used'])]
class LeavePeriod extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'entitlement' => 'integer',
            'used' => 'integer',
        ];
    }

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
        return $this->hasMany(LeaveBalanceEntry::class);
    }
}
