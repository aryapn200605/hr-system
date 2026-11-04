<?php

namespace Tests\Concerns;

use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\Department;
use App\Models\LeaveCategory;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\Decisions;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A small organization for history and recap tests, on Wednesday 4 November 2026, 10:00 WIB:
 *
 * - Siti (Approver) supervises Eko (Employee, Finance); Budi (Approver) supervises Rina (Employee,
 *   Sales) and Hana (HR Admin, no department). Siti has no department.
 * - Eko: annual leave 9–10 Nov approved by Siti, overtime 5 Nov 17–20 pending.
 * - Rina: sick leave 16–17 Nov pending.
 * - Siti: overtime 6 Nov 18–22 approved by Hana.
 * - Hana: other leave 23 Nov rejected by Budi.
 */
trait SeedsReportData
{
    use CreatesRequesters;

    protected const REASON = 'Private reason text that never leaves the request.';

    protected User $siti;

    protected User $budi;

    protected User $eko;

    protected User $rina;

    protected User $hana;

    protected Department $finance;

    protected Department $sales;

    /** @var array<string, LeaveRequest|OvertimeRequest> */
    protected array $requests = [];

    protected function seedReportData(): void
    {
        $this->seedRequestData();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));

        $this->finance = Department::create(['name' => 'Finance', 'is_active' => true]);
        $this->sales = Department::create(['name' => 'Sales', 'is_active' => true]);

        $this->hana = $this->member(RoleName::HrAdmin, ['name' => 'Hana HR']);
        $this->siti = $this->member(RoleName::Approver, ['name' => 'Siti Supervisor']);
        $this->budi = $this->member(RoleName::Approver, ['name' => 'Budi Supervisor']);
        $this->eko = $this->member(RoleName::Employee, ['name' => 'Eko Employee']);
        $this->rina = $this->member(RoleName::Employee, ['name' => 'Rina Employee']);

        $this->eko->forceFill(['department_id' => $this->finance->id])->save();
        $this->rina->forceFill(['department_id' => $this->sales->id])->save();

        $this->supervise($this->siti, $this->eko);
        $this->supervise($this->budi, $this->rina);
        $this->supervise($this->budi, $this->hana);

        $decisions = app(Decisions::class);

        $this->requests['eko-leave'] = $this->leaveRequest($this->eko, 'annual', '2026-11-09', '2026-11-10');
        $decisions->approve($this->siti, 'leave', $this->requests['eko-leave']->id);
        $this->travel(1)->minutes();
        $this->requests['eko-overtime'] = $this->overtimeRequest($this->eko, '2026-11-05 17:00', '2026-11-05 20:00');
        $this->travel(1)->minutes();
        $this->requests['rina-leave'] = $this->leaveRequest($this->rina, 'sick', '2026-11-16', '2026-11-17');
        $this->travel(1)->minutes();
        $this->requests['siti-overtime'] = $this->overtimeRequest($this->siti, '2026-11-06 18:00', '2026-11-06 22:00');
        $decisions->approve($this->hana, 'overtime', $this->requests['siti-overtime']->id);
        $this->travel(1)->minutes();
        $this->requests['hana-leave'] = $this->leaveRequest($this->hana, 'other', '2026-11-23', '2026-11-23');
        $decisions->reject($this->budi, 'leave', $this->requests['hana-leave']->id, 'Not this week, sorry.');

        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00'));

        foreach ([$this->hana, $this->siti, $this->budi, $this->eko, $this->rina] as $user) {
            $user->refresh();
        }
    }

    protected function leaveRequest(User $user, string $category, string $start, string $end): LeaveRequest
    {
        RateLimiter::clear('requests:submit:'.$user->id);

        return app(LeaveRequests::class)->submit($user, ['category' => $category, 'starts_on' => $start, 'ends_on' => $end, 'reason' => self::REASON]);
    }

    protected function overtimeRequest(User $user, string $start, string $end): OvertimeRequest
    {
        RateLimiter::clear('requests:submit:'.$user->id);

        return app(OvertimeRequests::class)->submit($user, ['start_at' => $start, 'end_at' => $end, 'reason' => self::REASON]);
    }

    /**
     * A row written straight to the table, for volume (pagination, query counts) without the
     * services' throttle and overlap rules.
     */
    protected function rawOvertime(User $user, string $start, int $hours = 2, RequestStatus $status = RequestStatus::Approved, ?User $decider = null): OvertimeRequest
    {
        $startAt = CarbonImmutable::parse($start);
        $endAt = $startAt->addHours($hours);

        $request = new OvertimeRequest;
        $request->forceFill([
            'user_id' => $user->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'hours' => $hours,
            'first_date' => $startAt->toDateString(),
            'last_date' => $endAt->subHour()->toDateString(),
            'reason' => self::REASON,
            'status' => $status,
            'decided_by' => $decider?->id,
            'decided_at' => $decider ? now() : null,
        ])->save();

        return $request;
    }

    protected function rawLeave(User $user, string $start, string $end, string $category = 'sick', RequestStatus $status = RequestStatus::Approved): LeaveRequest
    {
        $request = new LeaveRequest;
        $request->forceFill([
            'user_id' => $user->id,
            'leave_category_id' => LeaveCategory::query()->where('code', $category)->value('id'),
            'starts_on' => $start,
            'ends_on' => $end,
            'working_days' => 1,
            'deducts_balance' => false,
            'reason' => self::REASON,
            'status' => $status,
        ])->save();

        return $request;
    }
}
