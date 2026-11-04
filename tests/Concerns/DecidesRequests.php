<?php

namespace Tests\Concerns;

use App\Actions\SubmitLeaveRequest;
use App\Actions\SubmitOvertimeRequest;
use App\Enums\RoleName;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\LeaveBalances;
use App\Support\SupervisorRelations;

trait DecidesRequests
{
    use MakesRequests;

    protected function leave(User $user, string $start, ?string $end = null, string $code = 'annual'): LeaveRequest
    {
        return app(SubmitLeaveRequest::class)->handle($user, $this->leaveInput($start, $end, $code), null);
    }

    protected function overtime(User $user, string $start, string $end): OvertimeRequest
    {
        return app(SubmitOvertimeRequest::class)->handle($user, $this->overtimeInput($start, $end), null);
    }

    /**
     * Someone of any role, linked to a supervisor, with an open period.
     */
    protected function linkedTo(User $supervisor, RoleName $role = RoleName::Employee): User
    {
        $user = $this->person($role);

        SupervisorRelations::assign($user, $supervisor);
        LeaveBalances::openFirstPeriod($user);

        return $user;
    }
}
