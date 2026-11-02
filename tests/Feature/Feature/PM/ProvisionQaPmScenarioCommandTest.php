<?php

namespace Tests\Feature\Feature\PM;

use App\Models\PmChecksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionQaPmScenarioCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuses_non_isolated_database_without_creating_fixture(): void
    {
        $this->artisan('qa:provision-pm-scenario')
            ->assertExitCode(1);

        $this->assertSame(0, PmChecksheet::withTrashed()->count());
    }
}
