<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class FoundationSmokeTest extends TestCase
{
    public function test_tests_never_run_against_the_application_database(): void
    {
        $this->assertSame('hr_system_test', DB::connection()->getDatabaseName());
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
