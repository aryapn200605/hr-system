<?php

namespace App\Models\Concerns;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The decision columns of a request: who decided, when, and the note or rejection reason, plus the
 * soft review lock. Written only by App\Services\Requests\Decisions and ReviewLock.
 */
trait HasDecision
{
    /**
     * Merge the decision casts into the model's own.
     */
    public function initializeHasDecision(): void
    {
        $this->mergeCasts([
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'review_locked_by' => 'integer',
            'review_locked_until' => 'datetime',
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewLocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_locked_by');
    }

    /**
     * "leave" or "overtime", as in the routes.
     */
    public function routeType(): string
    {
        return $this instanceof LeaveRequest ? 'leave' : 'overtime';
    }
}
