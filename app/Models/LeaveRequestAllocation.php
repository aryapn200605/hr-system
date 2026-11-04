<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The working days of a deducting leave request inside one leave period. A null leave_period_id
 * means the period did not exist yet at submission and was projected from the anchor date.
 */
class LeaveRequestAllocation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days' => 'integer',
            'charged_at' => 'datetime',
        ];
    }

    public function isProjected(): bool
    {
        return $this->leave_period_id === null;
    }

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
