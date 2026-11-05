<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Approvals\Index as ApprovalIndex;
use App\Livewire\Approvals\Show as ApprovalShow;
use App\Livewire\Dashboard;
use App\Livewire\Departments\Index as DepartmentIndex;
use App\Livewire\History\Index as HistoryIndex;
use App\Livewire\History\Show as HistoryShow;
use App\Livewire\Holidays\Index as HolidayIndex;
use App\Livewire\Profile\LoginActivity;
use App\Livewire\Profile\Sessions;
use App\Livewire\Profile\Show as ProfileShow;
use App\Livewire\Recap\Index as RecapIndex;
use App\Livewire\Requests\Index as RequestIndex;
use App\Livewire\Requests\Show as RequestShow;
use App\Livewire\Security\Index as SecurityIndex;
use App\Livewire\Users\Index as UserIndex;
use App\Livewire\Users\LeaveBalance;
use App\Livewire\Users\Show as UserShow;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Requests\Evidence;
use App\Services\Requests\LeaveRequests;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

/**
 * Phase 14: who may open what, every page route and every Livewire page component, as a guest and as
 * each role, matched against DECISIONS.md sections 3 and 7 and SPEC.md (README "Authorization matrix").
 * The routes are tried by URL; the components are mounted directly, which is what a manipulated Livewire
 * request reaches after the route; the boot() checks run on every later call too. Deeper per-action
 * refusals (forged IDs, locked properties, losing a permission mid-page) are in the feature tests of each
 * module; a few representative ones are repeated here.
 */
class AuthorizationMatrixTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    /** @var array<string, User> */
    private array $as = [];

    private User $other;

    private LeaveRequest $own;

    private LeaveRequest $unrelated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        Storage::fake(Evidence::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));

        $this->as['hr'] = $this->member(RoleName::HrAdmin);
        $this->as['approver'] = $this->member(RoleName::Approver);
        $this->as['employee'] = $this->member(RoleName::Employee);
        // The Approver supervises the Employee and the HR Admin (DECISIONS.md section 3).
        $this->supervise($this->as['approver'], $this->as['employee']);
        $this->supervise($this->as['approver'], $this->as['hr']);
        $this->other = $this->supervisedMember();

        $submit = fn (User $user) => app(LeaveRequests::class)->submit($user, [
            'category' => 'annual', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-10', 'reason' => 'Family matters out of town.',
        ], UploadedFile::fake()->createWithContent('note.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n"));

        $this->own = $submit($this->as['employee']);
        $this->unrelated = $submit($this->other);
    }

    /**
     * Expected HTTP status per route and actor. 302 for a guest is the redirect to /login.
     *
     * @return array<string, array{0: string, 1: array<string, int>}>
     */
    private function routes(): array
    {
        $user = $this->as['employee']->id;
        $own = 'leave/'.$this->own->id;
        $unrelated = 'leave/'.$this->unrelated->id;

        //                                                       guest  employee approver hr
        return [
            'dashboard' => ['/dashboard', $this->row(302, 200, 200, 200)],
            'users' => ['/users', $this->row(302, 403, 403, 200)],
            'user detail' => ["/users/$user", $this->row(302, 403, 403, 200)],
            'user leave balance' => ["/users/$user/leave-balance", $this->row(302, 403, 403, 200)],
            'departments' => ['/departments', $this->row(302, 403, 403, 200)],
            'holidays' => ['/holidays', $this->row(302, 403, 403, 200)],
            'my requests' => ['/requests', $this->row(302, 200, 200, 200)],
            'own request' => ["/requests/$own", $this->row(302, 200, 404, 404)],
            'own evidence' => ["/requests/$own/evidence", $this->row(302, 200, 200, 404)],
            'unrelated evidence' => ["/requests/$unrelated/evidence", $this->row(302, 404, 404, 404)],
            'approvals' => ['/approvals', $this->row(302, 403, 200, 200)],
            'approval of subordinate' => ["/approvals/$own", $this->row(302, 403, 200, 404)],
            'approval of unrelated' => ["/approvals/$unrelated", $this->row(302, 403, 404, 404)],
            'history' => ['/history', $this->row(302, 200, 200, 200)],
            'history detail own/subordinate' => ["/history/$own", $this->row(302, 200, 200, 200)],
            'history detail unrelated' => ["/history/$unrelated", $this->row(302, 404, 404, 200)],
            'recap' => ['/recap', $this->row(302, 200, 200, 200)],
            'history export' => ['/history/export', $this->row(302, 200, 200, 200)],
            'balance export' => ['/recap/balance/export', $this->row(302, 200, 200, 200)],
            'department export' => ['/recap/departments/export', $this->row(302, 200, 200, 200)],
            'profile' => ['/profile', $this->row(302, 200, 200, 200)],
            'sessions' => ['/profile/sessions', $this->row(302, 200, 200, 200)],
            'login history' => ['/profile/login-history', $this->row(302, 200, 200, 200)],
            'security' => ['/security', $this->row(302, 200, 200, 200)],
            'login page' => ['/login', $this->row(200, 302, 302, 302)],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function row(int $guest, int $employee, int $approver, int $hr): array
    {
        return compact('guest', 'employee', 'approver', 'hr');
    }

    public function test_every_page_route_answers_each_actor_as_decided(): void
    {
        $mismatches = [];

        foreach ($this->routes() as $name => [$url, $expected]) {
            foreach ($expected as $actor => $status) {
                $this->app['auth']->forgetGuards();
                $this->flushSession();
                $request = $actor === 'guest' ? $this : $this->actingAs($this->as[$actor]);
                $actual = $request->get($url)->getStatusCode();

                if ($actual !== $status) {
                    $mismatches[] = "$name ($url) as $actor: expected $status, got $actual";
                }
            }
        }

        $this->assertSame([], $mismatches);
    }

    public function test_every_page_component_refuses_a_direct_livewire_mount_by_whoever_may_not_open_it(): void
    {
        $user = (string) $this->as['employee']->id;
        $own = ['type' => 'leave', 'id' => (string) $this->own->id];
        $unrelated = ['type' => 'leave', 'id' => (string) $this->unrelated->id];

        //                                                                        employee approver hr
        $components = [
            [Dashboard::class, [], [200, 200, 200]],
            [UserIndex::class, [], [403, 403, 200]],
            [UserShow::class, ['user' => $user], [403, 403, 200]],
            [LeaveBalance::class, ['user' => $user], [403, 403, 200]],
            [DepartmentIndex::class, [], [403, 403, 200]],
            [HolidayIndex::class, [], [403, 403, 200]],
            [RequestIndex::class, [], [200, 200, 200]],
            [RequestShow::class, $own, [200, 404, 404]],
            [ApprovalIndex::class, [], [403, 200, 200]],
            [ApprovalShow::class, $own, [403, 200, 404]],
            [ApprovalShow::class, $unrelated, [403, 404, 404]],
            [HistoryIndex::class, [], [200, 200, 200]],
            [HistoryShow::class, $unrelated, [404, 404, 200]],
            [RecapIndex::class, [], [200, 200, 200]],
            [ProfileShow::class, [], [200, 200, 200]],
            [Sessions::class, [], [200, 200, 200]],
            [LoginActivity::class, [], [200, 200, 200]],
            [SecurityIndex::class, [], [200, 200, 200]],
        ];

        $mismatches = [];

        foreach ($components as [$class, $params, $expected]) {
            foreach (['employee', 'approver', 'hr'] as $i => $actor) {
                $actual = $this->mountStatus($class, $params, $this->as[$actor]);

                if ($actual !== $expected[$i]) {
                    $mismatches[] = class_basename($class).' '.json_encode($params)." as $actor: expected {$expected[$i]}, got $actual";
                }
            }
        }

        $this->assertSame([], $mismatches);
    }

    private function mountStatus(string $class, array $params, User $actor): int
    {
        try {
            $component = Livewire::actingAs($actor)->test($class, $params);
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode();
        }

        foreach ([403, 404] as $code) {
            try {
                $code === 403 ? $component->assertForbidden() : $component->assertNotFound();

                return $code;
            } catch (AssertionFailedError) {
            }
        }

        $component->assertOk();

        return 200;
    }

    public function test_forged_livewire_actions_are_refused_on_the_server(): void
    {
        // An Approver on the user list cannot even mount it, so a forged suspend never runs.
        Livewire::actingAs($this->as['approver'])->test(UserIndex::class)->assertForbidden();

        // An HR Admin cannot adjust their own balance (DECISIONS.md section 9), even by calling the action.
        Livewire::actingAs($this->as['hr'])->test(LeaveBalance::class, ['user' => (string) $this->as['hr']->id])
            ->set('days', '1')->set('reason', 'Forged own adjustment.')->call('adjust')->assertForbidden();

        // A decider cannot decide a request of someone they do not supervise by changing the URL's ID.
        Livewire::actingAs($this->as['approver'])->test(ApprovalShow::class, ['type' => 'leave', 'id' => (string) $this->unrelated->id])
            ->assertNotFound();

        // A requester cannot cancel another user's request by sending its ID.
        Livewire::actingAs($this->as['employee'])->test(RequestShow::class, ['type' => 'leave', 'id' => (string) $this->unrelated->id])
            ->assertNotFound();

        // Nobody decides their own request: the HR Admin's own request is not theirs to decide.
        $hrOwn = app(LeaveRequests::class)->submit($this->as['hr'], [
            'category' => 'sick', 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-16', 'reason' => 'Family matters out of town.',
        ]);
        Livewire::actingAs($this->as['hr'])->test(ApprovalShow::class, ['type' => 'leave', 'id' => (string) $hrOwn->id])->assertNotFound();
        // Its assigned supervisor may (DECISIONS.md section 3).
        Livewire::actingAs($this->as['approver'])->test(ApprovalShow::class, ['type' => 'leave', 'id' => (string) $hrOwn->id])->assertOk();
    }
}
