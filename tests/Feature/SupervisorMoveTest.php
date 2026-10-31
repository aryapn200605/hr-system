<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Exceptions\AccountProtectionException;
use App\Exceptions\SupervisorGuardException;
use App\Livewire\Supervisors\Move;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SupervisorRelations;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReplaysLivewire;
use Tests\TestCase;

class SupervisorMoveTest extends TestCase
{
    use RefreshDatabase, ReplaysLivewire;

    private User $admin;

    private User $from;

    private User $to;

    private User $first;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->userWithRole(RoleName::HrAdmin);
        $this->from = $this->userWithRole(RoleName::Approver);
        $this->to = $this->userWithRole(RoleName::Approver);
        $this->first = $this->userWithRole(RoleName::Employee);
        $this->second = $this->userWithRole(RoleName::Employee);

        SupervisorRelations::assign($this->first, $this->from);
        SupervisorRelations::assign($this->second, $this->from);
    }

    private function userWithRole(RoleName $role): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create();
    }

    private function page(): mixed
    {
        return Livewire::actingAs($this->admin)->test(Move::class);
    }

    /**
     * @return list<int>
     */
    private function subordinatesOf(User $user): array
    {
        return $user->subordinates()->orderBy('users.id')->pluck('users.id')->all();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    public function test_everyone_moves_in_one_step_and_is_audited(): void
    {
        $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('toId', (string) $this->to->id)
            ->call('move')
            ->assertHasNoErrors()
            ->assertSet('notice', '');

        $this->assertSame([], $this->subordinatesOf($this->from));
        $this->assertSame([$this->first->id, $this->second->id], $this->subordinatesOf($this->to));

        $entry = AuditLog::where('event', AuditEvent::SupervisorsMoved->value)->sole();

        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertSame($this->from->id, $entry->subject_id);
        $this->assertSame($this->to->id, $entry->metadata['to_supervisor_id']);
        $this->assertSame([$this->first->id, $this->second->id], $entry->metadata['employee_ids']);
        $this->assertSame('all', $entry->metadata['scope']);
    }

    public function test_only_the_chosen_people_move(): void
    {
        $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('toId', (string) $this->to->id)
            ->set('scope', 'selected')
            ->set('selected', [(string) $this->first->id])
            ->call('move');

        $this->assertSame([$this->second->id], $this->subordinatesOf($this->from));
        $this->assertSame([$this->first->id], $this->subordinatesOf($this->to));
        $this->assertSame('selected', AuditLog::where('event', AuditEvent::SupervisorsMoved->value)->sole()->metadata['scope']);
    }

    public function test_someone_already_under_the_new_supervisor_keeps_one_link(): void
    {
        SupervisorRelations::assign($this->first, $this->to);

        SupervisorRelations::move($this->from, $this->to, null, $this->admin);

        $this->assertSame([$this->first->id, $this->second->id], $this->subordinatesOf($this->to));
        $this->assertSame([], $this->subordinatesOf($this->from));
    }

    public function test_a_move_keeps_the_other_supervisors_of_an_employee(): void
    {
        $other = $this->userWithRole(RoleName::Approver);
        SupervisorRelations::assign($this->first, $other);

        SupervisorRelations::move($this->from, $this->to, null, $this->admin);

        $this->assertSame([$this->first->id], $this->subordinatesOf($other));
    }

    public function test_a_move_records_who_made_the_new_links(): void
    {
        SupervisorRelations::move($this->from, $this->to, null, $this->admin);

        $this->assertSame($this->admin->id, DB::table('employee_supervisor')->where('supervisor_id', $this->to->id)->value('created_by'));
    }

    public function test_a_move_is_all_or_nothing_when_one_employee_cannot_move(): void
    {
        $approverEmployee = $this->userWithRole(RoleName::Approver);
        SupervisorRelations::assign($approverEmployee, $this->from);
        SupervisorRelations::assign($this->to, $approverEmployee);

        try {
            SupervisorRelations::move($this->from, $this->to, null, $this->admin);
            $this->fail('The move went through.');
        } catch (SupervisorGuardException $exception) {
            $this->assertStringContainsString('already above', $exception->getMessage());
        }

        $this->assertCount(3, $this->subordinatesOf($this->from));
        $this->assertSame([], $this->subordinatesOf($this->to));
        $this->assertSame(0, AuditLog::where('event', AuditEvent::SupervisorsMoved->value)->count());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::SupervisorGuardRefused->value)->count());
    }

    public function test_the_new_supervisor_cannot_be_among_those_moved(): void
    {
        SupervisorRelations::assign($this->to, $this->from);

        $page = $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('toId', (string) $this->to->id)
            ->call('move');

        $this->assertStringContainsString('their own supervisor', $page->get('notice'));
        $this->assertCount(3, $this->subordinatesOf($this->from));
    }

    public function test_someone_who_is_not_a_subordinate_cannot_be_smuggled_in(): void
    {
        $outsider = $this->userWithRole(RoleName::Employee);

        $page = $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('toId', (string) $this->to->id)
            ->set('scope', 'selected')
            ->set('selected', [(string) $this->first->id, (string) $outsider->id])
            ->call('move');

        $this->assertStringContainsString('Nothing was moved', $page->get('notice'));
        $this->assertSame([], $this->subordinatesOf($this->to));
    }

    public function test_an_empty_choice_moves_nothing(): void
    {
        $page = $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('toId', (string) $this->to->id)
            ->set('scope', 'selected')
            ->call('move');

        $this->assertStringContainsString('Nothing was moved', $page->get('notice'));
    }

    public function test_a_supervisor_without_subordinates_has_nothing_to_move(): void
    {
        $this->expectException(AccountProtectionException::class);

        SupervisorRelations::move($this->to, $this->from, null, $this->admin);
    }

    public function test_both_supervisors_are_required_and_must_differ(): void
    {
        $this->page()->call('move')->assertHasErrors(['fromId', 'toId']);

        $page = $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('toId', (string) $this->from->id)
            ->call('move');

        $this->assertStringContainsString('two different supervisors', $page->get('notice'));
    }

    public function test_an_unknown_scope_is_refused(): void
    {
        $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('toId', (string) $this->to->id)
            ->set('scope', 'everyone-ever')
            ->call('move')
            ->assertHasErrors('scope');

        $this->assertSame([], $this->subordinatesOf($this->to));
    }

    public function test_moving_from_an_inactive_supervisor_is_the_way_out(): void
    {
        SupervisorRelations::assign($this->first, $this->userWithRole(RoleName::Approver));
        SupervisorRelations::assign($this->second, $this->userWithRole(RoleName::Approver));

        $this->from->suspend($this->admin);

        $this->assertSame(2, SupervisorRelations::move($this->from->fresh(), $this->to, null, $this->admin));
    }

    public function test_changing_the_source_clears_the_choice(): void
    {
        $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('selected', [(string) $this->first->id])
            ->set('fromId', (string) $this->to->id)
            ->assertSet('selected', []);
    }

    public function test_the_page_lists_subordinates_of_the_chosen_supervisor(): void
    {
        $this->page()
            ->set('fromId', (string) $this->from->id)
            ->set('scope', 'selected')
            ->assertSee($this->first->name)
            ->assertSee($this->second->name);
    }

    public function test_the_move_refuses_an_actor_without_the_permission(): void
    {
        $this->expectException(AuthorizationException::class);

        SupervisorRelations::move($this->from, $this->to, null, $this->first);
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_the_page_is_refused_on_a_direct_url(RoleName $role): void
    {
        $this->actingAs($this->userWithRole($role))->get(route('supervisors.move'))->assertForbidden();
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_a_replayed_snapshot_is_refused(RoleName $role): void
    {
        $snapshot = $this->snapshotOf($this->admin, route('supervisors.move'));

        $this->actingAs($this->userWithRole($role));

        $this->callLivewire($snapshot, 'move', [], [
            'fromId' => (string) $this->from->id,
            'toId' => (string) $this->to->id,
        ])->assertForbidden();

        $this->assertSame([], $this->subordinatesOf($this->to));
    }

    public function test_forged_input_is_refused(): void
    {
        $snapshot = $this->snapshotOf($this->admin, route('supervisors.move'));

        $this->callLivewire($snapshot, null, [], ['fromId' => ['1']])->assertStatus(422);
        $this->callLivewire($snapshot, null, [], ['selected' => [['nested']]])->assertStatus(422);
    }
}
