<?php

namespace App\Exceptions;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A refusal by App\Support\SupervisorGuard. It is an AccountProtectionException, so every page that
 * shows a guard's refusal shows this one too. User::withinHrAdminLock() audits it after the rollback.
 */
class SupervisorGuardException extends AccountProtectionException
{
    private const NAMES_SHOWN = 10;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(string $message, public readonly User $subject, public readonly array $metadata)
    {
        parent::__construct($message);
    }

    /**
     * Name who would be left without a supervisor and the way out.
     *
     * @param  Collection<int, User>  $stranded
     */
    public static function wouldStrand(string $action, User $supervisor, Collection $stranded): self
    {
        $names = $stranded->take(self::NAMES_SHOWN)->pluck('name')->implode(', ');
        $more = $stranded->count() - self::NAMES_SHOWN;

        return new self(
            sprintf(
                '%s: %s%s would have no active supervisor left. Give them another supervisor first, or use Move subordinates.',
                self::refusedAction($action, $supervisor),
                $names,
                $more > 0 ? " and {$more} more" : '',
            ),
            $supervisor,
            ['reason' => 'would_strand', 'action' => $action, 'employee_ids' => $stranded->pluck('id')->all()],
        );
    }

    public static function notEligible(User $supervisor, string $action): self
    {
        return new self(
            $supervisor->name.' cannot be a supervisor: a supervisor must be an active Approver.',
            $supervisor,
            ['reason' => 'not_eligible', 'action' => $action],
        );
    }

    public static function ownSupervisor(User $employee, string $action): self
    {
        return new self(
            $employee->name.' cannot be their own supervisor. Leave them out, or choose another supervisor.',
            $employee,
            ['reason' => 'own_supervisor', 'action' => $action],
        );
    }

    public static function loop(User $employee, User $supervisor, string $action): self
    {
        return new self(
            sprintf('%s cannot supervise %s, because %s is already above them. Remove that link first.', $supervisor->name, $employee->name, $employee->name),
            $supervisor,
            ['reason' => 'loop', 'action' => $action, 'employee_ids' => [$employee->getKey()]],
        );
    }

    private static function refusedAction(string $action, User $supervisor): string
    {
        return match ($action) {
            'suspend' => 'Cannot suspend '.$supervisor->name,
            'change_role' => 'Cannot change the role of '.$supervisor->name,
            'delete' => 'Cannot delete '.$supervisor->name,
            default => 'Cannot remove '.$supervisor->name.' as supervisor',
        };
    }
}
