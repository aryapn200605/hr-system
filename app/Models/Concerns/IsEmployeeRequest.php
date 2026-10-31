<?php

namespace App\Models\Concerns;

use App\Enums\RequestStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What leave and overtime requests share: a requester, a status and optional evidence.
 */
trait IsEmployeeRequest
{
    /**
     * Scope the query to requests that keep hold of their dates.
     */
    #[Scope]
    protected function holding(Builder $query): void
    {
        $query->whereIn('status', RequestStatus::holding());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine whether the requester can still cancel this request.
     */
    public function isPending(): bool
    {
        return $this->status === RequestStatus::Pending;
    }

    /**
     * Determine whether a file came with this request.
     */
    public function hasEvidence(): bool
    {
        return $this->evidence_path !== null;
    }
}
