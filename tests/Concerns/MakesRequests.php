<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\LeaveCategory;
use App\Models\User;
use App\Support\LeaveBalances;
use App\Support\SupervisorRelations;

trait MakesRequests
{
    protected const VALID_REASON = 'Family matter out of town';

    protected function person(RoleName $role): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create();
    }

    /**
     * An Employee with an active supervisor and an open leave period.
     */
    protected function supervisedEmployee(?User $supervisor = null): User
    {
        $employee = $this->person(RoleName::Employee);

        SupervisorRelations::assign($employee, $supervisor ?? $this->person(RoleName::Approver));
        LeaveBalances::openFirstPeriod($employee);

        return $employee;
    }

    protected function category(string $code): LeaveCategory
    {
        return LeaveCategory::query()->where('code', $code)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function leaveInput(string $start, ?string $end = null, string $code = 'annual', array $overrides = []): array
    {
        return [
            'leave_category_id' => $this->category($code)->id,
            'start_date' => $start,
            'end_date' => $end ?? $start,
            'reason' => self::VALID_REASON,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function overtimeInput(string $start, string $end, array $overrides = []): array
    {
        return ['start_at' => $start, 'end_at' => $end, 'reason' => self::VALID_REASON, ...$overrides];
    }
}
