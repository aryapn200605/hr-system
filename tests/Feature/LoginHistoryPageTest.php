<?php

namespace Tests\Feature;

use App\Enums\LoginResult;
use App\Livewire\Security\LoginActivity;
use App\Models\LoginHistory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class LoginHistoryPageTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = $this->enrolledUser();
    }

    private function login(?User $user, LoginResult $result, string $ip = '203.0.113.9', ?string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'): LoginHistory
    {
        return LoginHistory::create([
            'user_id' => $user?->id,
            'result' => $result->value,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'occurred_at' => now(),
        ]);
    }

    public function test_the_page_shows_time_ip_user_agent_and_result(): void
    {
        $this->login($this->user, LoginResult::Success);
        $this->login($this->user, LoginResult::FailedTotp, '198.51.100.4');

        $this->actingAs($this->user)->get(route('security.login-history'))
            ->assertOk()
            ->assertSeeInOrder(['Time', 'IP address', 'User agent', 'Result'])
            ->assertSee(now()->format('j M Y'))
            ->assertSee('203.0.113.9')
            ->assertSee('198.51.100.4')
            ->assertSee('Chrome on Windows')
            ->assertSee('Successful')
            ->assertSee('Wrong authenticator code');
    }

    public function test_the_full_user_agent_stays_available_and_escaped(): void
    {
        $this->login($this->user, LoginResult::Success, userAgent: '"><script>alert(1)</script>');

        $this->actingAs($this->user)->get(route('security.login-history'))
            ->assertSee('alert(1)')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_only_the_users_own_rows_appear(): void
    {
        $this->login($this->user, LoginResult::Success, '203.0.113.1');
        $this->login($this->enrolledUser(), LoginResult::Success, '203.0.113.2');
        $this->login(null, LoginResult::FailedCredentials, '203.0.113.3');

        $this->actingAs($this->user)->get(route('security.login-history'))
            ->assertSee('203.0.113.1')
            ->assertDontSee('203.0.113.2')
            ->assertDontSee('203.0.113.3');
    }

    public function test_a_client_cannot_ask_for_another_users_rows(): void
    {
        $other = $this->enrolledUser();
        $this->login($other, LoginResult::Success, '203.0.113.2');

        $this->actingAs($this->user)->get(route('security.login-history', ['user' => $other->id, 'user_id' => $other->id]))
            ->assertOk()
            ->assertDontSee('203.0.113.2');
    }

    public function test_rows_are_paginated_newest_first(): void
    {
        foreach (range(1, 16) as $number) {
            $this->travel(1)->minutes();
            $this->login($this->user, LoginResult::Success, "198.51.100.{$number}");
        }

        Livewire::actingAs($this->user)->test(LoginActivity::class)
            ->assertSee('Showing 1 to 15 of 16')
            ->assertSee('198.51.100.16')
            ->assertDontSee('198.51.100.1<')
            ->call('nextPage')
            ->assertSee('Showing 16 to 16 of 16')
            ->assertSee('198.51.100.1')
            ->assertDontSee('198.51.100.16');
    }

    public function test_a_hostile_page_number_falls_back_safely(): void
    {
        $this->login($this->user, LoginResult::Success);

        $this->actingAs($this->user)->get(route('security.login-history', ['page' => "1' OR 1=1"]))->assertOk();
        $this->actingAs($this->user)->get(route('security.login-history', ['page' => 'x']))->assertOk();
    }

    public function test_an_empty_history_says_so(): void
    {
        $this->actingAs($this->user)->get(route('security.login-history'))
            ->assertOk()
            ->assertSee('No login activity yet');
    }
}
