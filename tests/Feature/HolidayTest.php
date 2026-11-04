<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Livewire\Holidays\Index;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Holidays;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->admin = $this->member(RoleName::HrAdmin);
    }

    public function test_hr_admin_adds_edits_and_deletes_a_holiday_with_audit(): void
    {
        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('openCreate')
            ->set('date', '2026-12-25')
            ->set('name', '  Christmas   Day ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->assertSee('Christmas Day');

        $holiday = Holiday::query()->sole();
        $this->assertSame(['2026-12-25', 'Christmas Day'], [$holiday->date->toDateString(), $holiday->name]);

        Livewire::actingAs($this->admin)->test(Index::class, ['year' => '2026'])
            ->call('openEdit', $holiday->id)
            ->assertSet('date', '2026-12-25')
            ->set('date', '2026-12-26')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-12-26', $holiday->fresh()->date->toDateString());

        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('openDelete', $holiday->id)
            ->call('delete')
            ->assertDispatched('toast');

        $this->assertSame(0, Holiday::query()->count());
        $this->assertSame(
            [AuditEvent::HolidayCreated->value, AuditEvent::HolidayUpdated->value, AuditEvent::HolidayDeleted->value],
            AuditLog::query()->where('event', 'like', 'holiday.%')->orderBy('id')->pluck('event')->all(),
        );
        $updated = AuditLog::query()->where('event', AuditEvent::HolidayUpdated->value)->sole();
        $this->assertSame('2026-12-25', $updated->metadata['before']['date']);
    }

    public function test_saving_without_changes_writes_no_audit(): void
    {
        $holiday = app(Holidays::class)->create($this->admin, '2026-12-25', 'Christmas Day');

        app(Holidays::class)->update($this->admin, $holiday->id, '2026-12-25', 'Christmas Day');

        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::HolidayUpdated->value)->count());
    }

    public function test_a_date_can_hold_only_one_holiday(): void
    {
        app(Holidays::class)->create($this->admin, '2026-12-25', 'Christmas Day');

        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('openCreate')
            ->set('date', '2026-12-25')
            ->set('name', 'Another')
            ->call('save')
            ->assertHasErrors(['date'])
            ->assertSee('There is already a holiday on this date.');

        $this->assertSame(1, Holiday::query()->count());
    }

    public function test_invalid_input_is_refused(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Index::class)->call('openCreate');

        foreach ([['', 'Name'], ['1999-12-31', 'Name'], ['2029-01-01', 'Name'], ['25/12/2026', 'Name'], ['2026-12-25', ''], ['2026-12-25', str_repeat('a', 101)]] as [$date, $name]) {
            $component->set('date', $date)->set('name', $name)->call('save')->assertHasErrors();
        }

        $component->set('date', '2028-12-31')->set('name', 'Last allowed day')->call('save')->assertHasNoErrors();
        $this->assertSame(1, Holiday::query()->count());
    }

    public function test_the_year_filter_shows_only_that_year_and_ignores_unknown_values(): void
    {
        app(Holidays::class)->create($this->admin, '2026-12-25', 'Christmas Day');
        app(Holidays::class)->create($this->admin, '2027-01-01', 'New Year');

        Livewire::actingAs($this->admin)->test(Index::class, ['year' => '2027'])->assertSee('New Year')->assertDontSee('Christmas Day');
        Livewire::actingAs($this->admin)->test(Index::class, ['year' => '1066'])->assertSee('Christmas Day')->assertDontSee('New Year');
    }

    public function test_others_are_refused_by_route_component_and_service(): void
    {
        foreach ([RoleName::Employee, RoleName::Approver] as $role) {
            $user = $this->member($role);

            $this->actingAs($user)->get(route('holidays.index'))->assertForbidden();
            Livewire::actingAs($user)->test(Index::class)->assertForbidden();
        }

        $this->expectException(AuthorizationException::class);
        app(Holidays::class)->create($this->member(RoleName::Employee), '2026-12-25', 'Christmas Day');
    }

    public function test_forged_requests_are_refused(): void
    {
        $holiday = app(Holidays::class)->create($this->admin, '2026-12-25', 'Christmas Day');

        Livewire::actingAs($this->admin)->test(Index::class)->set('name', ['x'])->assertStatus(422);
        Livewire::actingAs($this->admin)->test(Index::class)->call('openEdit', 'abc')->assertNotFound();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin)->test(Index::class)->set('targetId', $holiday->id);
    }

    public function test_the_page_is_in_the_menu_for_hr_admin_only(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('holidays.index'));
        $this->actingAs($this->member(RoleName::Employee))->get(route('dashboard'))->assertDontSee(route('holidays.index'));
    }
}
