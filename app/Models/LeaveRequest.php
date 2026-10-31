<?php

namespace App\Models;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Models\Concerns\IsEmployeeRequest;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Status and decision columns are not mass assignable, so they cannot arrive with form input.
 */
#[Hidden(['evidence_path'])]
class LeaveRequest extends Model
{
    use IsEmployeeRequest;

    public const CANCELLED_EVENT = AuditEvent::LeaveRequestCancelled;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => RequestStatus::Pending->value,
    ];

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
            'working_days' => 'integer',
            'status' => RequestStatus::class,
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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
        return $this->hasMany(LeaveRequestAllocation::class);
    }
}
