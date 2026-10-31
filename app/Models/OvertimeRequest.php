<?php

namespace App\Models;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Models\Concerns\IsEmployeeRequest;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Status and decision columns are not mass assignable, so they cannot arrive with form input.
 */
#[Hidden(['evidence_path'])]
class OvertimeRequest extends Model
{
    use IsEmployeeRequest;

    public const CANCELLED_EVENT = AuditEvent::OvertimeRequestCancelled;

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
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'hours' => 'integer',
            'status' => RequestStatus::class,
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
