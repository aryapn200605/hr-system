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
     * Whether the system decided this request (no decider), for example on suspension.
     */
    public function decidedBySystem(): bool
    {
        return $this->system_reason !== null;
    }

    /**
     * Who decided, in the viewer's language: the system, the decider's name, or a removed user.
     * Needs the decider loaded.
     */
    public function deciderLabel(): string
    {
        if ($this->decidedBySystem()) {
            return __('System');
        }

        return $this->decider?->name ?? __('a removed user');
    }

    /**
     * The note or rejection reason. A system decision has a fixed reason, translated for the viewer.
     */
    public function decisionText(): ?string
    {
        return match ($this->system_reason) {
            null => $this->decision_note,
            self::systemReasonSuspended() => __('Rejected automatically because the account was suspended.'),
            default => __('Rejected automatically by the system.'),
        };
    }

    /**
     * The system_reason code of an automatic rejection when the requester is suspended.
     */
    public static function systemReasonSuspended(): string
    {
        return 'account_suspended';
    }

    /**
     * "leave" or "overtime", as in the routes.
     */
    public function routeType(): string
    {
        return $this instanceof LeaveRequest ? 'leave' : 'overtime';
    }
}
