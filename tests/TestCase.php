<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Livewire keeps per-request state in static properties (for example "a component was rendered in this
        // request", which decides whether its scripts are injected). In production every request starts clean;
        // in the test suite one PHP process runs every test, so an earlier test could leave that state set and a
        // later test would pass or fail depending on the order. Reset it before every test.
        Livewire::flushState();

        $this->withoutVite();
    }
}
