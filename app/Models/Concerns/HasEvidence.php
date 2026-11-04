<?php

namespace App\Models\Concerns;

/**
 * Optional evidence stored on the private "evidence" disk under a random name.
 */
trait HasEvidence
{
    public function hasEvidence(): bool
    {
        return $this->evidence_path !== null;
    }
}
