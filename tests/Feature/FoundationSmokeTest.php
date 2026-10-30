<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class FoundationSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_never_run_against_the_application_database(): void
    {
        $this->assertSame('hr_system_test', DB::connection()->getDatabaseName());
    }

    public function test_users_table_matches_the_account_schema(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'email_verified_at'));
        $this->assertTrue(Schema::hasColumns('users', ['username', 'status', 'two_factor_enrolled_at', 'last_login_at']));
    }

    public function test_livewire_renders_a_component(): void
    {
        $component = new class extends Component
        {
            public function render(): string
            {
                return '<div>Livewire is running</div>';
            }
        };

        Livewire::test($component)->assertSee('Livewire is running');
    }
}
