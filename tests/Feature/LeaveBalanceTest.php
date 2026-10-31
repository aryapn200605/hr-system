<?php

namespace Tests\Feature;

use App\Actions\CreateUserWithInvitation;
use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Enums\RoleName;
use App\Exceptions\AccountProtectionException;
use App\Livewire\Users\LeaveBalance;
use App\Models\AuditLog;
use App\Models\LeaveBalanceEntry;
use App\Models\LeaveBalancePeriod;
use App\Models\User;
use App\Support\LeaveBalances;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReplaysLivewire;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase, ReplaysLivewire;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));

        $this->admin = User::factory()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create();
        $this->employee = User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();

        LeaveBalances::openFirstPeriod($this->employee);
    }

    private function page(?User $actor = null): mixed
    {
        return Livewire::actingAs($actor ?? $this->admin)->test(LeaveBalance::class, ['user' => (string) $this->employee->id]);
    }

    private function open(?User $user = null): LeaveBalancePeriod
    {
        return ($user ?? $this->employee)->leavePeriods()->whereNull('closed_at')->sole();
    }

    private function travelToDate(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 00:10:00'));
    }

    private function runCheck(): void
    {
        $this->artisan('app:reset-leave-balances')->assertSuccessful();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function resetDates(): array
    {
        return [
            'later this year' => ['2024-09-01', '2026-06-15', '2026-09-01'],
            'already passed this year' => ['2024-03-01', '2026-06-15', '2027-03-01'],
            'the anniversary itself goes to next year' => ['2024-06-15', '2026-06-15', '2027-06-15'],
            'the day before the anniversary' => ['2024-06-15', '2026-06-14', '2026-06-15'],
            '29 February in a common year' => ['2024-02-29', '2026-01-10', '2026-02-28'],
            '29 February returns in a leap year' => ['2024-02-29', '2027-03-10', '2028-02-29'],
            '31 December' => ['2024-12-31', '2026-12-31', '2027-12-31'],
            '1 January after the last day of the year' => ['2024-01-01', '2026-12-31', '2027-01-01'],
        ];
    }

    #[DataProvider('resetDates')]
    public function test_the_next_reset_comes_from_the_anchor(string $anchor, string $day, string $expected): void
    {
        $this->assertSame(
            $expected,
            LeaveBalances::nextResetAfter(CarbonImmutable::parse($anchor), CarbonImmutable::parse($day))->toDateString(),
        );
    }

    public function test_a_new_invited_user_gets_a_first_period_of_twelve_days(): void
    {
        $result = app(CreateUserWithInvitation::class)->handle([
            'name' => 'Nia', 'username' => 'nia', 'email' => 'nia@example.com', 'role' => RoleName::Employee->value,
        ], $this->admin);

        $period = $this->open($result->user);

        $this->assertSame('2026-06-15', $period->start_date->toDateString());
        $this->assertSame('2027-06-14', $period->end_date->toDateString());
        $this->assertSame(12, $period->entitlement);
        $this->assertSame(0, $period->used);

        $entry = $period->entries()->sole();

        $this->assertSame(LeaveEntryType::Opened, $entry->type);
        $this->assertSame($this->admin->id, $entry->actor_id);
    }

    public function test_the_first_admin_gets_a_first_period(): void
    {
        DB::table('model_has_roles')->delete();

        $admin = User::createFirstHrAdmin(['name' => 'A', 'username' => 'a', 'email' => 'a@example.com', 'password' => 'Correct-Horse-42']);

        $this->assertSame(12, $this->open($admin)->entitlement);
    }

    public function test_the_anchor_starts_as_the_day_the_account_was_created(): void
    {
        $this->assertSame('2026-06-15', $this->employee->fresh()->leave_anchor_date->toDateString());
    }

    public function test_nothing_happens_before_the_reset_date(): void
    {
        $this->travelToDate('2027-06-14');

        $this->runCheck();

        $this->assertSame(1, $this->employee->leavePeriods()->count());
    }

    public function test_the_reset_date_closes_the_old_period_and_opens_twelve_days_without_carry_over(): void
    {
        $old = $this->open();
        $old->forceFill(['used' => 5])->save();

        $this->travelToDate('2027-06-15');
        $this->runCheck();

        $old->refresh();
        $new = $this->open();

        $this->assertNotNull($old->closed_at);
        $this->assertSame(5, $old->used);
        $this->assertSame('2027-06-15', $new->start_date->toDateString());
        $this->assertSame('2028-06-14', $new->end_date->toDateString());
        $this->assertSame(12, $new->entitlement);
        $this->assertSame(0, $new->used);
    }

    public function test_the_reset_is_recorded_in_the_ledger_and_the_audit_log(): void
    {
        $old = $this->open();
        $old->forceFill(['used' => 5])->save();

        $this->travelToDate('2027-06-15');
        $this->runCheck();

        $this->assertStringContainsString('7 unused days expired', $old->entries()->where('type', LeaveEntryType::Closed)->sole()->reason);
        $this->assertSame(LeaveEntryType::Opened, $this->open()->entries()->sole()->type);

        $entry = AuditLog::where('event', AuditEvent::LeaveBalanceReset->value)->sole();

        $this->assertSame($this->employee->id, $entry->subject_id);
        $this->assertSame(7, $entry->metadata['forfeited']);
        $this->assertSame('daily_check', $entry->metadata['source']);
    }

    public function test_running_the_check_twice_on_one_day_changes_nothing_more(): void
    {
        $this->travelToDate('2027-06-15');

        $this->runCheck();
        $this->runCheck();

        $this->assertSame(2, $this->employee->leavePeriods()->count());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::LeaveBalanceReset->value)->count());
    }

    public function test_a_check_that_missed_years_catches_up_period_by_period(): void
    {
        $this->travelToDate('2029-08-01');

        $this->runCheck();

        $starts = $this->employee->leavePeriods()->orderBy('start_date')->get()->map(fn ($period) => $period->start_date->toDateString());

        $this->assertSame(['2026-06-15', '2027-06-15', '2028-06-15', '2029-06-15'], $starts->all());
        $this->assertSame('2030-06-14', $this->open()->end_date->toDateString());
    }

    public function test_periods_touch_end_to_end(): void
    {
        $this->travelToDate('2029-08-01');
        $this->runCheck();

        $periods = $this->employee->leavePeriods()->orderBy('start_date')->get();

        foreach ($periods->slice(1)->values() as $index => $period) {
            $this->assertSame($periods[$index]->end_date->addDay()->toDateString(), $period->start_date->toDateString());
        }
    }

    public function test_an_anchor_on_29_february_keeps_its_day_across_years(): void
    {
        $this->travelToDate('2024-02-29');
        $user = User::factory()->withRole(RoleName::Employee)->create();
        LeaveBalances::openFirstPeriod($user);

        $this->travelToDate('2029-03-01');
        $this->runCheck();

        $starts = $user->leavePeriods()->orderBy('start_date')->pluck('start_date')->map->toDateString();

        $this->assertSame(['2024-02-29', '2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29', '2029-02-28'], $starts->all());
    }

    public function test_the_daily_check_opens_a_period_for_a_user_without_one(): void
    {
        $user = User::factory()->withRole(RoleName::Employee)->create();

        $this->runCheck();

        $this->assertSame(12, $this->open($user)->entitlement);
    }

    public function test_suspended_users_keep_rolling_over(): void
    {
        $this->employee->suspend($this->admin);

        $this->travelToDate('2027-06-15');
        $this->runCheck();

        $this->assertSame(2, $this->employee->leavePeriods()->count());
    }

    public function test_the_check_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'app:reset-leave-balances'));

        $this->assertNotNull($event);
        $this->assertSame('10 0 * * *', $event->expression);
    }

    public function test_an_admin_can_adjust_with_a_reason_and_the_ledger_keeps_both_sides(): void
    {
        $this->page()->call('openAdjust')
            ->set('entitlementChange', '2')
            ->set('usedChange', '3')
            ->set('reason', 'Leave taken before this system')
            ->call('adjust')
            ->assertHasNoErrors();

        $period = $this->open();

        $this->assertSame(14, $period->entitlement);
        $this->assertSame(3, $period->used);

        $entry = $period->entries()->where('type', LeaveEntryType::Adjusted)->sole();

        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertSame([12, 14, 0, 3], [$entry->entitlement_before, $entry->entitlement_after, $entry->used_before, $entry->used_after]);
        $this->assertSame('Leave taken before this system', $entry->reason);
        $this->assertNotNull($entry->created_at);

        $audit = AuditLog::where('event', AuditEvent::LeaveBalanceAdjusted->value)->sole();

        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertEquals(['before' => 12, 'after' => 14], $audit->metadata['entitlement']);
    }

    public function test_a_reason_is_required(): void
    {
        $this->page()->call('openAdjust')->set('entitlementChange', '1')->set('reason', '   ')->call('adjust')
            ->assertHasErrors(['reason' => 'required']);

        $this->assertSame(12, $this->open()->entitlement);
    }

    public function test_an_adjustment_must_change_something_and_be_a_whole_number(): void
    {
        $this->page()->call('openAdjust')->set('reason', 'x')->call('adjust')->assertHasErrors('entitlementChange');
        $this->page()->call('openAdjust')->set('entitlementChange', '1.5')->set('reason', 'x')->call('adjust')->assertHasErrors('entitlementChange');
        $this->page()->call('openAdjust')->set('usedChange', 'abc')->set('reason', 'x')->call('adjust')->assertHasErrors('usedChange');
    }

    public function test_the_limits_are_checked_on_the_stored_values(): void
    {
        $this->page()->call('openAdjust')->set('entitlementChange', '-13')->set('reason', 'x')->call('adjust')->assertHasErrors('entitlementChange');
        $this->page()->call('openAdjust')->set('usedChange', '13')->set('reason', 'x')->call('adjust')->assertHasErrors('usedChange');
        $this->page()->call('openAdjust')->set('usedChange', '-1')->set('reason', 'x')->call('adjust')->assertHasErrors('usedChange');
        $this->page()->call('openAdjust')->set('entitlementChange', '366')->set('reason', 'x')->call('adjust')->assertHasErrors('entitlementChange');

        $this->assertSame(1, $this->open()->entries()->count());
    }

    public function test_entitlement_cannot_drop_below_what_is_used(): void
    {
        LeaveBalances::adjust($this->employee, 0, 10, 'used', $this->admin);

        $this->expectException(ValidationException::class);

        LeaveBalances::adjust($this->employee, -3, 0, 'too low', $this->admin);
    }

    public function test_ledger_entries_cannot_be_changed_or_deleted(): void
    {
        $entry = $this->open()->entries()->sole();

        foreach ([fn () => $entry->update(['reason' => 'changed']), fn () => $entry->delete()] as $action) {
            try {
                $action();
                $this->fail('The entry changed.');
            } catch (LogicException) {
                $this->assertSame(1, LeaveBalanceEntry::count());
            }
        }
    }

    public function test_an_admin_can_reset_now(): void
    {
        $old = $this->open();

        $this->travelToDate('2026-09-01');

        $this->page()->call('openReset')->call('resetBalance')->assertSet('notice', '');

        $old->refresh();
        $new = $this->open();

        $this->assertSame('2026-08-31', $old->end_date->toDateString());
        $this->assertNotNull($old->closed_at);
        $this->assertSame('2026-09-01', $new->start_date->toDateString());
        $this->assertSame('2027-06-14', $new->end_date->toDateString());
        $this->assertSame(12, $new->entitlement);
        $this->assertSame('2026-06-15', $this->employee->fresh()->leave_anchor_date->toDateString());

        $entry = AuditLog::where('event', AuditEvent::LeaveBalanceReset->value)->sole();

        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertSame('ui', $entry->metadata['source']);
    }

    public function test_a_second_reset_on_the_same_day_is_refused(): void
    {
        $this->travelToDate('2026-09-01');

        $this->page()->call('resetBalance');
        $page = $this->page()->call('resetBalance');

        $this->assertStringContainsString('already started a new period today', $page->get('notice'));
        $this->assertSame(2, $this->employee->leavePeriods()->count());
    }

    public function test_a_reset_is_refused_on_the_day_a_period_started(): void
    {
        $page = $this->page()->call('resetBalance');

        $this->assertStringContainsString('already started', $page->get('notice'));
        $this->assertSame(1, $this->employee->leavePeriods()->count());
    }

    public function test_a_reset_after_an_overdue_period_rolls_over_first_and_does_not_double(): void
    {
        $this->travelToDate('2027-06-15');

        $this->page()->call('resetBalance')->assertSet('notice', '');
        $this->page()->call('resetBalance');

        $this->assertSame(2, $this->employee->leavePeriods()->count());
    }

    public function test_the_daily_check_after_a_manual_reset_changes_nothing(): void
    {
        $this->travelToDate('2026-09-01');

        $this->page()->call('resetBalance');
        $this->runCheck();

        $this->assertSame(2, $this->employee->leavePeriods()->count());
    }

    public function test_a_user_without_a_period_cannot_be_adjusted(): void
    {
        $user = User::factory()->withRole(RoleName::Employee)->create();

        $this->expectException(AccountProtectionException::class);

        LeaveBalances::adjust($user, 1, 0, 'x', $this->admin);
    }

    public function test_changing_the_anchor_moves_the_end_of_the_open_period_only(): void
    {
        $this->page()->call('openAnchor')
            ->assertSet('anchorDate', '2026-06-15')
            ->set('anchorDate', '2020-09-01')
            ->call('saveAnchor')
            ->assertHasNoErrors();

        $period = $this->open();

        $this->assertSame('2026-06-15', $period->start_date->toDateString());
        $this->assertSame('2026-08-31', $period->end_date->toDateString());
        $this->assertSame(12, $period->entitlement);
        $this->assertSame('2020-09-01', $this->employee->fresh()->leave_anchor_date->toDateString());
        $this->assertSame(LeaveEntryType::AnchorChanged, $period->entries()->latest('id')->first()->type);

        $entry = AuditLog::where('event', AuditEvent::LeaveAnchorChanged->value)->sole();

        $this->assertEquals(['before' => '2026-06-15', 'after' => '2020-09-01'], $entry->metadata['anchor']);
        $this->assertEquals(['before' => '2027-06-14', 'after' => '2026-08-31'], $entry->metadata['period_end']);
    }

    public function test_the_next_reset_follows_the_new_anchor(): void
    {
        LeaveBalances::changeAnchor($this->employee, CarbonImmutable::parse('2020-09-01'), $this->admin);

        $this->travelToDate('2026-09-01');
        $this->runCheck();

        $this->assertSame('2026-09-01', $this->open()->start_date->toDateString());
        $this->assertSame('2027-08-31', $this->open()->end_date->toDateString());
    }

    public function test_an_anchor_whose_date_passed_during_the_period_ends_it_next_year_and_not_in_the_past(): void
    {
        $this->travelToDate('2026-10-01');

        LeaveBalances::changeAnchor($this->employee, CarbonImmutable::parse('2020-08-01'), $this->admin);

        $this->assertSame('2027-07-31', $this->open()->end_date->toDateString());
    }

    public function test_an_anchor_on_today_never_ends_the_open_period_in_the_past(): void
    {
        LeaveBalances::changeAnchor($this->employee, CarbonImmutable::parse('2020-06-15'), $this->admin);

        $this->assertSame('2027-06-14', $this->open()->end_date->toDateString());
    }

    public function test_the_shortcuts_hold_the_real_dates(): void
    {
        $this->employee->forceFill(['joined_at' => '2024-03-15'])->save();

        $this->actingAs($this->admin)->get(route('users.leave-balance', $this->employee->id))
            ->assertSee("\$wire.anchorDate = '2026-06-15'", false)
            ->assertSee("\$wire.anchorDate = '2024-03-15'", false);
    }

    public function test_a_bad_anchor_is_refused(): void
    {
        $this->page()->set('anchorDate', '2026-06-16')->call('saveAnchor')->assertHasErrors('anchorDate');
        $this->page()->set('anchorDate', '')->call('saveAnchor')->assertHasErrors('anchorDate');
        $this->page()->set('anchorDate', '15-06-2026')->call('saveAnchor')->assertHasErrors('anchorDate');
    }

    public function test_the_command_handles_one_user(): void
    {
        $this->travelToDate('2027-06-15');

        $this->artisan('app:reset-leave-balances', ['username' => $this->employee->username])->assertSuccessful();

        $this->assertSame(2, $this->employee->leavePeriods()->count());
        $this->assertSame(0, $this->admin->leavePeriods()->count());

        $entry = AuditLog::where('event', AuditEvent::LeaveBalanceReset->value)->sole();

        $this->assertSame('console', $entry->metadata['source']);
        $this->assertNotEmpty($entry->metadata['operator']);
    }

    public function test_the_command_can_reset_a_user_now(): void
    {
        $this->travelToDate('2026-09-01');

        $this->artisan('app:reset-leave-balances', ['username' => $this->employee->username, '--now' => true])->assertSuccessful();
        $this->artisan('app:reset-leave-balances', ['username' => $this->employee->username, '--now' => true])->assertFailed();

        $this->assertSame(2, $this->employee->leavePeriods()->count());
        $this->assertNull(AuditLog::where('event', AuditEvent::LeaveBalanceReset->value)->sole()->actor_id);
    }

    public function test_the_command_refuses_bad_input(): void
    {
        $this->artisan('app:reset-leave-balances', ['username' => 'nobody'])->assertFailed();
        $this->artisan('app:reset-leave-balances', ['--now' => true])->assertFailed();
    }

    public function test_the_page_shows_the_balance_periods_and_changes(): void
    {
        LeaveBalances::adjust($this->employee, 0, 2, 'Taken in January', $this->admin);

        $this->actingAs($this->admin)->get(route('users.leave-balance', $this->employee->id))
            ->assertOk()
            ->assertSee('10 days')
            ->assertSee('15 Jun 2026 to 14 Jun 2027')
            ->assertSee('Taken in January')
            ->assertSee('WIB');
    }

    public function test_viewing_without_the_adjust_permission_shows_no_actions(): void
    {
        $viewer = User::factory()->twoFactorEnrolled()->create();
        $viewer->givePermissionTo('leave-balance.view');

        $this->actingAs($viewer)->get(route('users.leave-balance', $this->employee->id))
            ->assertOk()
            ->assertDontSee('Adjust balance</button>', false);

        $this->page($viewer)->call('adjust')->assertForbidden();
        $this->page($viewer)->call('resetBalance')->assertForbidden();
        $this->page($viewer)->call('saveAnchor')->assertForbidden();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_the_page_is_refused_on_a_direct_url(RoleName $role): void
    {
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create();

        $this->actingAs($user)->get(route('users.leave-balance', $this->employee->id))->assertForbidden();
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_a_replayed_snapshot_is_refused(RoleName $role): void
    {
        $snapshot = $this->snapshotOf($this->admin, route('users.leave-balance', $this->employee->id));

        $this->actingAs(User::factory()->twoFactorEnrolled()->withRole($role)->create());

        foreach (['openAdjust', 'adjust', 'openAnchor', 'saveAnchor', 'openReset', 'resetBalance'] as $method) {
            $this->callLivewire($snapshot, $method)->assertForbidden();
        }

        $this->assertSame(12, $this->open()->entitlement);
        $this->assertSame(1, $this->employee->leavePeriods()->count());
    }

    public function test_the_service_refuses_an_actor_without_the_permission(): void
    {
        $calls = [
            fn () => LeaveBalances::adjust($this->employee, 1, 0, 'x', $this->employee),
            fn () => LeaveBalances::resetNow($this->employee, $this->employee, 'ui'),
            fn () => LeaveBalances::changeAnchor($this->employee, CarbonImmutable::today(), $this->employee),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('The call went through.');
            } catch (AuthorizationException) {
                $this->assertSame(12, $this->open()->entitlement);
            }
        }
    }

    public function test_an_array_for_a_field_is_refused(): void
    {
        $snapshot = $this->snapshotOf($this->admin, route('users.leave-balance', $this->employee->id));

        $this->callLivewire($snapshot, null, [], ['reason' => ['x']])->assertStatus(422);
    }
}
