<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Users\Index;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class UserListTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->hrAdmin();
    }

    private function userList(array $query = []): Testable
    {
        return Livewire::actingAs($this->admin)->withQueryParams($query)->test(Index::class);
    }

    private function employee(array $attributes = []): User
    {
        return User::factory()->withRole(RoleName::Employee)->create($attributes);
    }

    /**
     * Assert which users a list shows, by their unique username.
     *
     * @param  list<User>  $shown
     * @param  list<User>  $hidden
     */
    private function assertLists(Testable $component, array $shown, array $hidden): void
    {
        foreach ($shown as $user) {
            $component->assertSee($user->username);
        }

        foreach ($hidden as $user) {
            $component->assertDontSee($user->username);
        }
    }

    public function test_the_list_shows_every_column_of_the_spec(): void
    {
        $user = User::factory()->twoFactorEnrolled()->withRole(RoleName::Approver)->create([
            'name' => 'Rina Wijaya',
            'username' => 'rina.w',
            'email' => 'rina@example.com',
            'last_login_at' => '2026-01-02 03:04:00',
            'created_at' => '2025-05-06 07:08:00',
        ]);

        $this->actingAs($this->admin)->get(route('users.index'))
            ->assertOk()
            ->assertSeeInOrder(['ID', 'Full name', 'Username', 'Email', 'Role', 'Status', '2FA', 'Last login', 'Created', 'Actions'])
            ->assertSeeInOrder([
                'Rina Wijaya', 'rina.w', 'rina@example.com', RoleName::Approver->value, 'Active', 'Enabled', '2 Jan 2026, 03:04', '6 May 2025, 07:08',
            ]);

        $this->assertNotNull($user->id);
    }

    public function test_an_empty_result_shows_the_empty_state(): void
    {
        $this->userList()->set('search', 'nobody-has-this')->assertSee('No users found');
    }

    public function test_search_matches_name_username_and_email(): void
    {
        $byName = $this->employee(['name' => 'Zebra Crossing']);
        $byUsername = $this->employee(['username' => 'zebra.user']);
        $byEmail = $this->employee(['email' => 'zebra@example.com']);
        $other = $this->employee(['name' => 'Nobody', 'username' => 'plain.user', 'email' => 'plain@example.com']);

        $this->assertLists($this->userList()->set('search', 'ZEBRA'), [$byName, $byUsername, $byEmail], [$other]);
    }

    public function test_search_ignores_surrounding_spaces(): void
    {
        $user = $this->employee(['username' => 'zebra.user']);
        $other = $this->employee(['username' => 'plain.user']);

        $this->assertLists($this->userList()->set('search', '  zebra  '), [$user], [$other]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function likeWildcards(): array
    {
        return [
            'percent' => ['100%', '100% Sure', '1000 Sure'],
            'underscore' => ['a_b', 'a_b Name', 'axb Name'],
            'backslash' => ['a\\b', 'a\\b Name', 'ab Name'],
        ];
    }

    #[DataProvider('likeWildcards')]
    public function test_like_wildcards_are_matched_literally(string $search, string $match, string $noMatch): void
    {
        $this->employee(['name' => $match]);
        $this->employee(['name' => $noMatch]);

        $this->userList()->set('search', $search)->assertSee($match)->assertDontSee($noMatch);
    }

    public function test_a_lone_percent_finds_nothing_instead_of_everyone(): void
    {
        $this->userList()->set('search', '%')->assertSee('No users found');
    }

    public function test_the_role_filter_works(): void
    {
        $approver = User::factory()->withRole(RoleName::Approver)->create();
        $employee = $this->employee();

        $this->assertLists($this->userList()->set('roleFilter', RoleName::Approver->value), [$approver], [$employee, $this->admin]);
    }

    public function test_the_status_filter_works(): void
    {
        $suspended = User::factory()->suspended()->withRole(RoleName::Employee)->create();
        $pending = User::factory()->pending()->withRole(RoleName::Employee)->create();

        $this->assertLists($this->userList()->set('statusFilter', UserStatus::Suspended->value), [$suspended], [$pending, $this->admin]);
    }

    public function test_filters_combine(): void
    {
        $match = User::factory()->suspended()->withRole(RoleName::Approver)->create(['name' => 'Zebra One']);
        $wrongRole = User::factory()->suspended()->withRole(RoleName::Employee)->create(['name' => 'Zebra Two']);
        $wrongStatus = User::factory()->withRole(RoleName::Approver)->create(['name' => 'Zebra Three']);
        $wrongName = User::factory()->suspended()->withRole(RoleName::Approver)->create(['name' => 'Other Four']);

        $component = $this->userList()
            ->set('search', 'zebra')
            ->set('roleFilter', RoleName::Approver->value)
            ->set('statusFilter', UserStatus::Suspended->value);

        $this->assertLists($component, [$match], [$wrongRole, $wrongStatus, $wrongName]);
    }

    public function test_filters_come_from_the_url(): void
    {
        $approver = User::factory()->withRole(RoleName::Approver)->create();
        $employee = $this->employee();

        $component = $this->userList(['role' => RoleName::Approver->value]);

        $this->assertLists($component->assertSet('roleFilter', RoleName::Approver->value), [$approver], [$employee]);
    }

    public function test_pagination_splits_the_list_newest_first(): void
    {
        $users = collect(range(1, 12))->map(fn () => $this->employee());

        $firstPage = $this->userList()->assertSee('Showing 1 to 10 of 13');

        $firstPage->assertSee($users->last()->username)->assertDontSee($users->first()->username);

        $firstPage->call('nextPage')
            ->assertSee('Showing 11 to 13 of 13')
            ->assertSee($users->first()->username)
            ->assertDontSee($users->last()->username);
    }

    public function test_the_page_size_can_be_changed(): void
    {
        collect(range(1, 12))->each(fn () => $this->employee());

        $this->userList()->set('perPage', '25')->assertSee('Showing 1 to 13 of 13');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function filters(): array
    {
        return [
            'search' => ['search', 'user'],
            'role' => ['roleFilter', RoleName::Employee->value],
            'status' => ['statusFilter', UserStatus::Active->value],
            'page size' => ['perPage', '25'],
        ];
    }

    #[DataProvider('filters')]
    public function test_changing_a_filter_goes_back_to_the_first_page(string $property, string $value): void
    {
        collect(range(1, 12))->each(fn () => $this->employee());

        $component = $this->userList()->call('nextPage');

        $this->assertSame(2, $component->instance()->getPage());

        $component->set($property, $value);

        $this->assertSame(1, $component->instance()->getPage());
    }

    public function test_a_page_past_the_end_lands_on_the_last_page(): void
    {
        collect(range(1, 12))->each(fn () => $this->employee());

        $this->userList(['page' => 99])->assertSee('Showing 11 to 13 of 13')->assertDontSee('No users found');
    }

    public function test_the_query_count_does_not_grow_with_the_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->userList(['per_page' => '50']);

            return count(DB::getQueryLog());
        };

        collect(range(1, 5))->each(fn () => User::factory()->withRole(RoleName::Employee)->create());

        // The first run fills the permission cache and the actor's relations.
        $count();
        $few = $count();

        collect(range(1, 30))->each(fn () => User::factory()->pending()->withRole(RoleName::Approver)->create());
        $many = $count();

        $this->assertSame($few, $many);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileUrls(): array
    {
        return [
            'quote' => ['search=%27%3B+DROP+TABLE+users%3B--'],
            'union' => ['search=%27+UNION+SELECT+password+FROM+users--'],
            'percent' => ['search=%25'],
            'backslash' => ['search=%5C'],
            'trailing backslash in a quote' => ['search=%27%5C'],
            'invalid utf-8' => ['search=%FF%FE'],
            'nul byte' => ['search=a%00b'],
            'emoji' => ['search=%F0%9F%98%80'],
            'very long' => ['search='.str_repeat('a', 5000)],
            'json array' => ['search=%5B1%5D'],
            'json object' => ['search=%7B%22a%22%3A1%7D'],
            'array' => ['search[]=x'],
            'nested array' => ['search[a][b]=x'],
            'number' => ['search=123'],
            'boolean' => ['search=true'],
            'null' => ['search=null'],
            'huge exponent' => ['search=1e999999'],
            'role unknown' => ['role=Superuser'],
            'role sql' => ['role=%27+OR+1%3D1--'],
            'role array' => ['role[]=HR+Admin'],
            'status unknown' => ['status=DELETED'],
            'status lowercase' => ['status=active'],
            'status array' => ['status[]=ACTIVE'],
            'page size huge' => ['per_page=1000000'],
            'page size zero' => ['per_page=0'],
            'page size negative' => ['per_page=-1'],
            'page size text' => ['per_page=abc'],
            'page size array' => ['per_page[]=10'],
            'page text' => ['page=abc'],
            'page negative' => ['page=-1'],
            'page zero' => ['page=0'],
            'page huge' => ['page=99999999999999999999'],
            'page array' => ['page[]=1'],
        ];
    }

    #[DataProvider('hostileUrls')]
    public function test_a_hostile_url_gives_a_normal_page(string $query): void
    {
        $response = $this->actingAs($this->admin)->get(route('users.index').'?'.$query);

        $response->assertOk()->assertSee('User Management');

        $this->assertSame(1, User::count());
    }

    public function test_an_invalid_filter_value_shows_everyone_and_not_nobody(): void
    {
        $component = $this->userList(['role' => 'Superuser', 'status' => 'DELETED', 'per_page' => '1000000']);

        $component->assertSet('roleFilter', '')
            ->assertSet('statusFilter', '')
            ->assertSet('perPage', '10')
            ->assertSee($this->admin->username);
    }

    public function test_the_phone_layout_labels_cells_from_one_markup_with_one_action_menu(): void
    {
        $employee = $this->employee();

        $html = $this->userList()->html();

        foreach (['ID', 'Username', 'Email', 'Role', 'Status', '2FA', 'Last login', 'Created'] as $label) {
            $this->assertStringContainsString('data-label="'.$label.'"', $html);
        }

        $this->assertStringContainsString('table-stack', $html);
        $this->assertSame(1, substr_count($html, 'id="user-actions-'.$employee->id.'"'));
    }

    public function test_an_oversized_search_is_cut(): void
    {
        $this->assertSame(100, mb_strlen($this->userList(['search' => str_repeat('a', 500)])->get('search')));
    }

    /**
     * Send a forged request to the real update endpoint.
     *
     * @param  array<string, mixed>  $updates
     * @param  list<array{method: string, params: array<int, mixed>}>  $calls
     */
    private function forge(array $updates = [], array $calls = []): TestResponse
    {
        $html = $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->getContent();

        preg_match('/wire:snapshot="([^"]+)"[^>]*wire:effects/', $html, $matches);

        return $this->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1]),
                'updates' => $updates ?: new stdClass,
                'calls' => array_map(fn (array $call) => [...$call, 'metadata' => new stdClass], $calls),
            ]],
        ], ['X-Livewire' => '1']);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function forgedUpdates(): array
    {
        return [
            'array search' => ['search', ['a', 'b']],
            'object search' => ['search', ['a' => 'b']],
            'null search' => ['search', null],
            'number search' => ['search', 5],
            'boolean search' => ['search', true],
            'nested search' => ['search.0', 'x'],
            'array role' => ['roleFilter', ['HR Admin']],
            'array status' => ['statusFilter', ['ACTIVE']],
            'array page size' => ['perPage', [10]],
            'array name' => ['name', ['x']],
            'array role field' => ['role', ['HR Admin']],
            'paginators' => ['paginators', ['page' => ['x']]],
            'nested paginators' => ['paginators.page', ['x']],
        ];
    }

    #[DataProvider('forgedUpdates')]
    public function test_a_forged_property_update_is_refused_without_a_server_error(string $property, mixed $value): void
    {
        $this->forge([$property => $value])->assertStatus(422);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forgedFilterValues(): array
    {
        return [
            'role' => ['roleFilter', 'Superuser'],
            'status' => ['statusFilter', 'DELETED'],
            'page size' => ['perPage', '1000000'],
        ];
    }

    #[DataProvider('forgedFilterValues')]
    public function test_a_forged_filter_string_is_replaced(string $property, string $value): void
    {
        $this->forge([$property => $value])->assertOk();
    }

    /**
     * @return array<string, array{string, array<int, mixed>}>
     */
    public static function forgedPageCalls(): array
    {
        return [
            'text' => ['setPage', ['abc']],
            'array' => ['setPage', [['x']]],
            'null' => ['setPage', [null]],
            'negative' => ['setPage', [-5]],
            'other paginator' => ['setPage', [2, 'other']],
            'goto text' => ['gotoPage', ['abc']],
            'goto array' => ['gotoPage', [['x']]],
        ];
    }

    #[DataProvider('forgedPageCalls')]
    public function test_a_forged_page_call_gives_a_normal_response(string $method, array $params): void
    {
        $this->forge(calls: [['method' => $method, 'params' => $params]])->assertOk();
    }
}
