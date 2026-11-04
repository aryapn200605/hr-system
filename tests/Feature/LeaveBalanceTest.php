<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Livewire\Users\LeaveBalance as LeaveBalancePage;
use App\Models\AuditLog;
use App\Models\LeaveBalanceEntry;
use App\Models\LeavePeriod;
use App\Models\User;
use App\Services\LeaveBalance;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    private const REASON = 'Correction agreed with the employee.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->admin = $this->hrAdmin();
    }

    private function balance(): LeaveBalance
    {
        return app(LeaveBalance::class);
    }

    private function employeeAnchoredOn(string $anchor): User
    {
        return User::factory()->withRole(RoleName::Employee)->create(['leave_anchor_date' => $anchor]);
    }

    /**
     * @return list<array{string, string}>
     */
    private function periodsOf(User $user): array
    {
        return LeavePeriod::query()->where('user_id', $user->id)->orderBy('starts_on')->get()
            ->map(fn (LeavePeriod $p) => [$p->starts_on->toDateString(), $p->ends_on->toDateString()])
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The input was accepted.');
    }

    // --- Opening periods ------------------------------------------------------------------------

    public function test_an_invited_user_gets_an_anchor_date_and_a_first_period_at_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:30'));

        $user = $this->invite()->user->fresh();

        // 23:30 WIB is still 4 November: the anchor follows WIB, not UTC.
        $this->assertSame('2026-11-04', $user->leave_anchor_date->toDateString());
        $this->assertSame([['2026-11-04', '2027-11-03']], $this->periodsOf($user));

        $entry = LeaveBalanceEntry::query()->where('user_id', $user->id)->sole();
        $this->assertSame(LeaveEntryType::Opened, $entry->type);
        $this->assertSame([12, 0, 12, null], [$entry->change, $entry->before, $entry->after, $entry->actor_id]);
    }

    public function test_the_first_period_is_written_in_the_transaction_that_creates_the_user(): void
    {
        $periods = LeavePeriod::query()->count();

        try {
            DB::transaction(function () {
                User::factory()->withRole(RoleName::Employee)->create(['username' => 'rolled.back']);

                throw new RuntimeException('The creation fails after the insert.');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(User::query()->where('username', 'rolled.back')->exists());
        $this->assertSame($periods, LeavePeriod::query()->count());
        $this->assertSame(User::query()->count(), LeavePeriod::query()->count());
    }

    public function test_the_first_period_of_an_older_anchor_is_the_one_containing_today(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');

        $this->assertSame([['2026-03-10', '2027-03-09']], $this->periodsOf($user));
        $this->assertSame(12, $this->balance()->sync($user)->remaining());
    }

    public function test_sync_is_idempotent_and_opens_the_new_period_on_the_anniversary(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');

        $this->balance()->sync($user);
        $this->balance()->sync($user);
        $this->assertCount(1, $this->periodsOf($user));

        $this->travelTo(CarbonImmutable::parse('2027-03-09 23:59'));
        $this->balance()->sync($user);
        $this->assertCount(1, $this->periodsOf($user));

        $this->travelTo(CarbonImmutable::parse('2027-03-10 00:00'));
        $current = $this->balance()->sync($user);

        $this->assertSame([['2026-03-10', '2027-03-09'], ['2027-03-10', '2028-03-09']], $this->periodsOf($user));
        $this->assertSame(12, $current->remaining());
        $this->assertSame('schedule', AuditLog::query()->where('event', AuditEvent::LeaveBalanceReset->value)->sole()->metadata['source']);
    }

    public function test_missed_days_are_caught_up_without_gaps(): void
    {
        $user = $this->employeeAnchoredOn('2025-06-01');

        $this->travelTo(CarbonImmutable::parse('2029-07-01'));
        $this->balance()->sync($user);

        $this->assertSame([
            ['2026-06-01', '2027-05-31'],
            ['2027-06-01', '2028-05-31'],
            ['2028-06-01', '2029-05-31'],
            ['2029-06-01', '2030-05-31'],
        ], $this->periodsOf($user));
    }

    public function test_a_29_february_anchor_falls_on_28_february_except_in_leap_years(): void
    {
        $this->travelTo(CarbonImmutable::parse('2024-03-01'));
        $user = $this->employeeAnchoredOn('2024-02-29');

        $this->travelTo(CarbonImmutable::parse('2028-03-01'));
        $this->balance()->sync($user);

        $this->assertSame([
            ['2024-02-29', '2025-02-27'],
            ['2025-02-28', '2026-02-27'],
            ['2026-02-28', '2027-02-27'],
            ['2027-02-28', '2028-02-28'],
            ['2028-02-29', '2029-02-27'],
        ], $this->periodsOf($user));
    }

    public function test_the_command_syncs_every_user_whatever_the_status(): void
    {
        $pending = User::factory()->pending()->withRole(RoleName::Employee)->create(['leave_anchor_date' => '2025-12-01']);
        $suspended = User::factory()->suspended()->withRole(RoleName::Employee)->create(['leave_anchor_date' => '2025-12-01']);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:05'));

        $this->artisan('app:leave-periods-sync')->expectsOutputToContain('Checked 3 users.')->assertSuccessful();

        foreach ([$pending, $suspended] as $user) {
            $this->assertSame([['2025-12-01', '2026-11-30'], ['2026-12-01', '2027-11-30']], $this->periodsOf($user));
        }
    }

    public function test_the_command_is_scheduled_daily_at_00_05(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'app:leave-periods-sync'));

        $this->assertNotNull($event);
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_ledger_is_append_only(): void
    {
        $entry = LeaveBalanceEntry::query()->where('user_id', $this->admin->id)->sole();

        try {
            $entry->update(['change' => 99]);
            $this->fail('A ledger row was changed.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    // --- Adjustments ---------------------------------------------------------------------------

    public function test_an_adjustment_changes_the_current_period_and_writes_the_ledger(): void
    {
        $user = $this->employeeAnchoredOn('2026-01-01');

        $period = $this->balance()->adjust($this->admin, $user, '2', '  '.self::REASON.'  ');
        $this->assertSame(14, $period->remaining());

        $period = $this->balance()->adjust($this->admin, $user, -14, self::REASON);
        $this->assertSame(0, $period->fresh()->remaining());

        $entries = LeaveBalanceEntry::query()->where('user_id', $user->id)->where('type', LeaveEntryType::Adjusted)->orderBy('id')->get();
        $this->assertSame([[2, 12, 14], [-14, 14, 0]], $entries->map(fn ($e) => [$e->change, $e->before, $e->after])->all());
        $this->assertSame(self::REASON, $entries->first()->reason);
        $this->assertSame($this->admin->id, $entries->first()->actor_id);

        $audit = AuditLog::query()->where('event', AuditEvent::LeaveBalanceAdjusted->value)->latest('id')->first();
        $this->assertEquals(['period_id' => $period->id, 'change' => -14, 'before' => 14, 'after' => 0], $audit->metadata);
    }

    public function test_an_adjustment_is_validated(): void
    {
        $user = $this->employeeAnchoredOn('2026-01-01');

        $this->assertArrayHasKey('days', $this->errorsOf(fn () => $this->balance()->adjust($this->admin, $user, -13, self::REASON)));
        $this->assertStringContainsString('Only 12 days remain', $this->errorsOf(fn () => $this->balance()->adjust($this->admin, $user, -13, self::REASON))['days'][0]);

        foreach (['0', '', '1.5', 'abc', 366, -366, ['1']] as $days) {
            $this->assertArrayHasKey('days', $this->errorsOf(fn () => $this->balance()->adjust($this->admin, $user, $days, self::REASON)), json_encode($days));
        }

        foreach (['', '   ', 'too short', str_repeat('a', 1001), ['x']] as $reason) {
            $this->assertArrayHasKey('reason', $this->errorsOf(fn () => $this->balance()->adjust($this->admin, $user, 1, $reason)));
        }

        $this->assertSame(12, $this->balance()->sync($user)->remaining());
        $this->assertSame(0, LeaveBalanceEntry::query()->where('user_id', $user->id)->where('type', LeaveEntryType::Adjusted)->count());
    }

    // --- Manual reset --------------------------------------------------------------------------

    public function test_a_manual_reset_closes_yesterday_and_opens_today_until_the_next_anniversary(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');
        $this->balance()->adjust($this->admin, $user, -5, self::REASON);

        $opened = $this->balance()->reset($this->admin, $user, self::REASON);

        $this->assertSame([['2026-03-10', '2026-11-03'], ['2026-11-04', '2027-03-09']], $this->periodsOf($user));
        $this->assertSame(12, $opened->remaining());
        $this->assertSame('2024-03-10', $user->fresh()->leave_anchor_date->toDateString(), 'The anchor does not move.');

        $closed = LeaveBalanceEntry::query()->where('type', LeaveEntryType::Closed)->sole();
        $this->assertSame([0, 7, 7, self::REASON], [$closed->change, $closed->before, $closed->after, $closed->reason]);

        $audit = AuditLog::query()->where('event', AuditEvent::LeaveBalanceReset->value)->sole();
        $this->assertSame('manual', $audit->metadata['source']);

        // The next anniversary still opens the following period.
        $this->travelTo(CarbonImmutable::parse('2027-03-10'));
        $this->balance()->sync($user);
        $this->assertSame(['2027-03-10', '2028-03-09'], $this->periodsOf($user)[2]);
    }

    public function test_a_reset_is_refused_when_the_current_period_started_today(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');
        $this->balance()->reset($this->admin, $user, self::REASON);

        $errors = $this->errorsOf(fn () => $this->balance()->reset($this->admin, $user, self::REASON));
        $this->assertSame('The current period started today, so there is nothing to reset.', $errors['reason'][0]);

        $fresh = User::factory()->withRole(RoleName::Employee)->create();
        $this->assertArrayHasKey('reason', $this->errorsOf(fn () => $this->balance()->reset($this->admin, $fresh, self::REASON)));
        $this->assertArrayHasKey('reason', $this->errorsOf(fn () => $this->balance()->reset($this->admin, $user, 'short')));

        $this->assertCount(2, $this->periodsOf($user));
    }

    // --- Anchor date ---------------------------------------------------------------------------

    public function test_a_new_anchor_applies_from_the_next_period(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');

        $this->balance()->changeAnchor($this->admin, $user, '2025-08-01');

        $this->assertSame('2025-08-01', $user->fresh()->leave_anchor_date->toDateString());
        $this->assertSame([['2026-03-10', '2027-03-09']], $this->periodsOf($user));
        $this->assertEquals(['from' => '2024-03-10', 'to' => '2025-08-01'], AuditLog::query()->where('event', AuditEvent::LeaveAnchorChanged->value)->sole()->metadata);

        $this->travelTo(CarbonImmutable::parse('2027-03-10'));
        $this->balance()->sync($user);

        // The next period ends on the first anniversary of the new anchor: a short one, then whole years.
        $this->assertSame(['2027-03-10', '2027-07-31'], $this->periodsOf($user)[1]);

        $this->travelTo(CarbonImmutable::parse('2027-08-01'));
        $this->balance()->sync($user);
        $this->assertSame(['2027-08-01', '2028-07-31'], $this->periodsOf($user)[2]);
    }

    public function test_an_anchor_later_than_the_current_start_ends_the_next_period_on_that_date(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');

        $this->balance()->changeAnchor($this->admin, $user, '2026-10-01');

        $this->travelTo(CarbonImmutable::parse('2027-03-10'));
        $this->balance()->sync($user);

        $this->assertSame(['2027-03-10', '2027-09-30'], $this->periodsOf($user)[1]);
    }

    public function test_the_anchor_is_validated(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');

        foreach (['2026-11-05', '1999-12-31', '10-03-2024', '2024-02-30', '', ['2024-01-01']] as $date) {
            $this->assertArrayHasKey('anchor', $this->errorsOf(fn () => $this->balance()->changeAnchor($this->admin, $user, $date)), json_encode($date));
        }

        $this->balance()->changeAnchor($this->admin, $user, '2026-11-04');
        $this->assertSame('2026-11-04', $user->fresh()->leave_anchor_date->toDateString());

        // Saving the same date again changes nothing and writes no audit row.
        $this->balance()->changeAnchor($this->admin, $user, '2026-11-04');
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::LeaveAnchorChanged->value)->count());
    }

    // --- Nobody changes their own balance -------------------------------------------------------

    public function test_an_admin_cannot_adjust_reset_or_re_anchor_their_own_balance(): void
    {
        foreach ([
            fn () => $this->balance()->adjust($this->admin, $this->admin, 1, self::REASON),
            fn () => $this->balance()->reset($this->admin, $this->admin, self::REASON),
            fn () => $this->balance()->changeAnchor($this->admin, $this->admin, '2025-01-01'),
        ] as $call) {
            try {
                $call();
                $this->fail('An admin changed their own balance.');
            } catch (AuthorizationException) {
            }
        }

        $page = Livewire::actingAs($this->admin)->test(LeaveBalancePage::class, ['user' => (string) $this->admin->id])
            ->assertSee('This is your own balance.')
            ->assertDontSeeHtml('wire:click="openAdjust"')
            ->assertDontSeeHtml('wire:click="openAnchor"');

        foreach (['openAdjust', 'openReset', 'openAnchor', 'useCreationDate'] as $method) {
            Livewire::actingAs($this->admin)->test(LeaveBalancePage::class, ['user' => (string) $this->admin->id])->call($method)->assertForbidden();
        }

        Livewire::actingAs($this->admin)->test(LeaveBalancePage::class, ['user' => (string) $this->admin->id])
            ->set('days', '5')->set('reason', self::REASON)->call('adjust')->assertForbidden();
        Livewire::actingAs($this->admin)->test(LeaveBalancePage::class, ['user' => (string) $this->admin->id])
            ->set('anchor', '2025-01-01')->call('saveAnchor')->assertForbidden();

        $this->assertSame(12, $this->balance()->sync($this->admin)->remaining());
        $this->assertSame('2026-11-04', $this->admin->fresh()->leave_anchor_date->toDateString());
    }

    public function test_another_admin_can_adjust_an_admins_balance(): void
    {
        $other = $this->hrAdmin();

        $this->balance()->adjust($other, $this->admin, 3, self::REASON);

        $this->assertSame(15, $this->balance()->sync($this->admin)->remaining());
    }

    // --- The page ------------------------------------------------------------------------------

    public function test_the_page_adjusts_resets_and_re_anchors(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');
        $page = fn () => Livewire::actingAs($this->admin)->test(LeaveBalancePage::class, ['user' => (string) $user->id]);

        $page()->assertSee('12 days')
            ->call('openAdjust')->set('days', '-2')->set('reason', self::REASON)->call('adjust')
            ->assertHasNoErrors()->assertDispatched('toast', message: 'Balance saved. 10 days remain.');

        $page()->call('openAdjust')->set('days', '0')->set('reason', 'short')->call('adjust')->assertHasErrors(['days', 'reason']);

        $page()->call('openReset')->set('reason', self::REASON)->call('resetBalance')->assertHasNoErrors();
        $page()->call('openReset')->set('reason', self::REASON)->call('resetBalance')->assertHasErrors(['reason']);

        $page()->call('openAnchor')->assertSet('anchor', '2024-03-10')
            ->call('useCreationDate')->assertSet('anchor', $user->created_at->toDateString())
            ->call('saveAnchor')->assertHasNoErrors();

        $this->assertSame('2026-11-04', $user->fresh()->leave_anchor_date->toDateString());
        $this->assertSame(3, LeaveBalanceEntry::query()->where('user_id', $user->id)->whereNotNull('actor_id')->count());

        $this->actingAs($this->admin)->get(route('users.leave-balance', $user->id))->assertOk()
            ->assertSeeInOrder(['Ledger', 'Period opened', 'Closed by reset', 'Adjusted'])
            ->assertSee(self::REASON);
    }

    public function test_forged_properties_are_refused(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');

        Livewire::actingAs($this->admin)->test(LeaveBalancePage::class, ['user' => (string) $user->id])->set('days', [1])->assertStatus(422);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin)->test(LeaveBalancePage::class, ['user' => (string) $user->id])->set('userId', $this->admin->id);
    }

    public function test_the_route_carries_the_permission_middleware(): void
    {
        $this->assertContains('can:'.PermissionName::LeaveBalanceView->value, Route::getRoutes()->getByName('users.leave-balance')->gatherMiddleware());
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_other_roles_are_refused(RoleName $role): void
    {
        $actor = User::factory()->twoFactorEnrolled()->withRole($role)->create();
        $user = $this->employeeAnchoredOn('2024-03-10');

        $this->actingAs($actor)->get(route('users.leave-balance', $user->id))->assertForbidden();
        Livewire::actingAs($actor)->test(LeaveBalancePage::class, ['user' => (string) $user->id])->assertForbidden();

        $this->expectException(AuthorizationException::class);
        $this->balance()->adjust($actor, $user, 1, self::REASON);
    }

    public function test_viewing_users_alone_is_not_enough_and_viewing_the_balance_allows_no_change(): void
    {
        $user = $this->employeeAnchoredOn('2024-03-10');
        $viewer = User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();
        $viewer->givePermissionTo(PermissionName::UsersView);

        $this->actingAs($viewer)->get(route('users.leave-balance', $user->id))->assertForbidden();
        // The component checks it itself: route middleware does not run for Livewire updates.
        Livewire::actingAs($viewer)->test(LeaveBalancePage::class, ['user' => (string) $user->id])->assertForbidden();

        $viewer->givePermissionTo(PermissionName::LeaveBalanceView);
        $this->startNextRequest();

        $this->actingAs($viewer->fresh())->get(route('users.leave-balance', $user->id))->assertOk()->assertDontSeeHtml('wire:click="openAdjust"');
        Livewire::actingAs($viewer->fresh())->test(LeaveBalancePage::class, ['user' => (string) $user->id])
            ->set('days', '5')->set('reason', self::REASON)->call('adjust')->assertForbidden();

        $this->assertSame(12, $this->balance()->sync($user)->remaining());
    }
}
