<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeding_preserves_reference_data_without_creating_privileged_users(): void
    {
        $this->seed();

        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseHas('locations', ['location_code' => 'LOC-PRD-A']);
        $this->assertDatabaseHas('machines', ['machine_code' => 'MCH-001']);
        $this->assertDatabaseHas('machines', ['machine_code' => 'MCH-002']);
    }

    public function test_default_seeding_does_not_reset_an_existing_privileged_account(): void
    {
        $chosenPassword = bin2hex(random_bytes(24));

        $existing = User::query()->create([
            'name' => 'Admin PRIME',
            'username' => 'admin.prime',
            'password' => $chosenPassword,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $hashBefore = $existing->getRawOriginal('password');

        $this->seed();

        $this->assertSame($hashBefore, $existing->fresh()->getRawOriginal('password'));
        $this->assertTrue(Auth::validate(['username' => 'admin.prime', 'password' => $chosenPassword]));
        $this->assertFalse(Auth::validate(['username' => 'admin.prime', 'password' => 'password']));
    }

    public function test_default_seeding_refuses_environments_other_than_local_and_testing(): void
    {
        app()->instance('env', 'production');

        $this->expectException(\RuntimeException::class);

        app(DatabaseSeeder::class)->run();
    }

    public function test_explicit_factory_fixture_authenticates_without_seeded_accounts(): void
    {
        $fixturePassword = bin2hex(random_bytes(24));

        $user = User::factory()->create([
            'role' => 'admin',
            'password' => $fixturePassword,
        ]);

        $this->assertTrue(Auth::attempt(['username' => $user->username, 'password' => $fixturePassword]));

        $this->assertSame(0, Location::query()->count());
        $this->assertSame(0, Machine::query()->count());
    }
}
