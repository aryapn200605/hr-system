<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Livewire\Holidays\Index;
use App\Models\AuditLog;
use App\Models\PublicHoliday;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReplaysLivewire;
use Tests\TestCase;

class PublicHolidayTest extends TestCase
{
    use RefreshDatabase, ReplaysLivewire;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create();
    }

    private function page(): mixed
    {
        return Livewire::actingAs($this->admin)->test(Index::class);
    }

    private function holiday(string $date = '2026-08-17'): PublicHoliday
    {
        return PublicHoliday::create(['date' => $date, 'name' => 'Independence Day']);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    public function test_an_hr_admin_can_add_a_holiday_and_it_is_audited(): void
    {
        $this->page()->call('openCreate')->set('date', '2026-08-17')->set('name', '  Independence Day ')->call('save')->assertHasNoErrors();

        $holiday = PublicHoliday::sole();

        $this->assertSame('Independence Day', $holiday->name);
        $this->assertSame($this->admin->id, $holiday->created_by);

        $entry = AuditLog::where('event', AuditEvent::PublicHolidayCreated->value)->sole();

        $this->assertSame('2026-08-17', $entry->metadata['date']);
        $this->assertSame($this->admin->id, $entry->actor_id);
    }

    public function test_a_holiday_can_be_changed(): void
    {
        $holiday = $this->holiday();

        $this->page()->call('openEdit', $holiday->id)
            ->assertSet('date', '2026-08-17')
            ->set('date', '2026-08-18')
            ->set('name', 'Moved')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-08-18', $holiday->fresh()->date->toDateString());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::PublicHolidayUpdated->value)->count());
    }

    public function test_saving_without_a_change_records_nothing(): void
    {
        $this->page()->call('openEdit', $this->holiday()->id)->call('save');

        $this->assertSame(0, AuditLog::where('event', AuditEvent::PublicHolidayUpdated->value)->count());
    }

    public function test_a_holiday_can_be_deleted(): void
    {
        $holiday = $this->holiday();

        $this->page()->call('openDelete', $holiday->id)->call('delete');

        $this->assertNull($holiday->fresh());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::PublicHolidayDeleted->value)->count());
    }

    public function test_input_is_validated(): void
    {
        $this->page()->call('openCreate')->set('name', 'x')->call('save')->assertHasErrors(['date' => 'required']);
        $this->page()->call('openCreate')->set('date', '17-08-2026')->set('name', 'x')->call('save')->assertHasErrors('date');
        $this->page()->call('openCreate')->set('date', '2026-08-17')->set('name', '  ')->call('save')->assertHasErrors(['name' => 'required']);
        $this->page()->call('openCreate')->set('date', '2026-08-17')->set('name', str_repeat('a', 151))->call('save')->assertHasErrors(['name' => 'max']);

        $this->assertSame(0, PublicHoliday::count());
    }

    public function test_a_date_can_have_one_holiday(): void
    {
        $this->holiday();

        $this->page()->call('openCreate')->set('date', '2026-08-17')->set('name', 'Twice')->call('save')->assertHasErrors(['date' => 'unique']);

        $this->assertSame(1, PublicHoliday::count());
    }

    public function test_the_database_refuses_a_second_holiday_on_a_date(): void
    {
        $this->holiday();

        $this->expectException(QueryException::class);

        DB::table('public_holidays')->insert(['date' => '2026-08-17', 'name' => 'Twice']);
    }

    public function test_the_page_lists_holidays(): void
    {
        $this->holiday();

        $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk()->assertSee('Independence Day')->assertSee('17 Aug 2026');
    }

    public function test_the_menu_shows_only_to_those_who_may_open_it(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee('Public holidays');

        $this->actingAs(User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create())
            ->get(route('dashboard'))->assertDontSee('Public holidays');
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_the_page_is_refused_on_a_direct_url(RoleName $role): void
    {
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create();

        $this->actingAs($user)->get(route('holidays.index'))->assertForbidden();
        Livewire::actingAs($user)->test(Index::class)->assertForbidden();
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_a_replayed_snapshot_is_refused(RoleName $role): void
    {
        $holiday = $this->holiday();
        $snapshot = $this->snapshotOf($this->admin, route('holidays.index'));

        $this->actingAs(User::factory()->twoFactorEnrolled()->withRole($role)->create());

        $this->callLivewire($snapshot, 'openDelete', [$holiday->id])->assertForbidden();
        $this->callLivewire($snapshot, 'delete')->assertForbidden();
        $this->callLivewire($snapshot, 'save', [], ['date' => '2026-09-01', 'name' => 'Forged'])->assertForbidden();

        $this->assertSame(1, PublicHoliday::count());
    }

    public function test_forged_input_is_refused(): void
    {
        $this->page()->call('openEdit', 'abc')->assertNotFound();
        $this->page()->call('openDelete', 999_999)->assertNotFound();

        $snapshot = $this->snapshotOf($this->admin, route('holidays.index'));

        $this->callLivewire($snapshot, null, [], ['date' => ['x']])->assertStatus(422);
    }
}
