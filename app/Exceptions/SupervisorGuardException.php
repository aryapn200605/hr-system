<?php

namespace App\Exceptions;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A refusal by App\Services\SupervisorGuard. It is an AccountProtectionException, so every screen
 * that already shows those refusals shows this one too.
 */
class SupervisorGuardException extends AccountProtectionException
{
    /**
     * Names shown before "and N more".
     */
    private const NAMES_SHOWN = 5;

    /**
     * @param  Collection<int, User>  $affected
     * @param  list<int>  $supervisorIds
     */
    public function __construct(public readonly Collection $affected, public readonly array $supervisorIds)
    {
        $names = $affected->take(self::NAMES_SHOWN)->pluck('name')->implode(', ');

        if ($affected->count() > self::NAMES_SHOWN) {
            $names .= ' '.__('and :count more', ['count' => $affected->count() - self::NAMES_SHOWN]);
        }

        parent::__construct(__('This would leave :names without an active supervisor. Give them another supervisor first, or move them to another supervisor, then try again.', ['names' => $names]));
    }
}
