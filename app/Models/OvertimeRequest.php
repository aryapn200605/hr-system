<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Models\Concerns\HasDecision;
use App\Models\Concerns\HasEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An overtime request, in whole WIB hours. Created and cancelled only through
 * App\Services\Requests\OvertimeRequests. Nothing is mass assignable.
 */
class OvertimeRequest extends Model
{
    use HasDecision, HasEvidence;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'hours' => 'integer',
            'first_date' => 'date',
            'last_date' => 'date',
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
}
