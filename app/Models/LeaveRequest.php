<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Models\Concerns\HasEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A leave or permission request. Created and cancelled only through App\Services\Requests\LeaveRequests.
 * Nothing is mass assignable: the service sets every column itself.
 */
class LeaveRequest extends Model
{
    use HasEvidence;

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
            'working_days' => 'integer',
            'deducts_balance' => 'boolean',
            'status' => RequestStatus::class,
            'cancelled_at' => 'datetime',
            'evidence_size' => 'integer',
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
     * @return BelongsTo<LeaveCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LeaveCategory::class, 'leave_category_id');
    }

    /**
     * @return HasMany<LeaveRequestAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(LeaveRequestAllocation::class)->orderBy('period_starts_on');
    }
}
