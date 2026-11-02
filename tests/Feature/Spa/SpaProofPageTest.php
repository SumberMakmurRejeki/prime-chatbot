<?php

namespace Tests\Feature\Spa;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SpaProofPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/internal/spa-proof')->assertRedirect('/login');
    }

    public function test_operator_and_inactive_admin_are_denied(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($operator)->get('/internal/spa-proof')->assertRedirect('/403');
        $this->actingAs($inactiveAdmin)->get('/internal/spa-proof')->assertRedirect('/403');
    }

    public function test_active_admin_receives_inertia_page_with_typed_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'name' => 'Admin SPA']);

        $this->actingAs($admin)
            ->get('/internal/spa-proof')
            ->assertViewIs('spa')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SpaProof')
                ->url('/internal/spa-proof')
                ->where('auth.name', 'Admin SPA')
                ->where('auth.role', 'admin')
                ->where('proofStage', 'Inertia 3 + Vue 3 + TypeScript')
                ->where('state', 'server')
                ->where('clientVisitUrl', route('internal.spa-proof', ['visit' => 'client']))
                ->where('serverVisitUrl', route('internal.spa-proof'))
            );
    }

    public function test_inertia_client_visit_receives_the_json_page_payload(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $initialPage = $this->actingAs($admin)->get('/internal/spa-proof')->viewData('page');

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $initialPage['version'],
        ])->get('/internal/spa-proof?visit=client');

        $response->assertOk()->assertHeader('X-Inertia', 'true');

        $this->assertSame('SpaProof', $response->json('component'));
        $this->assertSame('client', $response->json('props.state'));
    }

    public function test_validated_query_parameter_drives_the_second_read_only_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->get('/internal/spa-proof?visit=client')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SpaProof')
                ->url('/internal/spa-proof?visit=client')
                ->where('state', 'client')
            );

        $this->actingAs($admin)
            ->get('/internal/spa-proof?visit=sekarang')
            ->assertRedirect()
            ->assertSessionHasErrors('visit');
    }

    public function test_page_exposes_only_the_declared_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $page = $this->actingAs($admin)->get('/internal/spa-proof')->assertOk()->viewData('page');

        $this->assertSame([
            'auth',
            'clientVisitUrl',
            'errors',
            'proofStage',
            'serverVisitUrl',
            'state',
        ], collect(array_keys($page['props']))->sort()->values()->all());

        $this->assertSame(['name', 'role'], collect(array_keys($page['props']['auth']))->sort()->values()->all());
    }

    public function test_page_is_closed_outside_local_and_qa_environments(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        config(['app.env' => 'production']);

        $this->actingAs($admin)->get('/internal/spa-proof')->assertNotFound();
    }

    public function test_existing_blade_routes_still_render(): void
    {
        // Root Vue hanya hidup di view Inertia; halaman Blade tidak memiliki titik mount.
        $this->get('/login')
            ->assertOk()
            ->assertSee('Login')
            ->assertDontSee('id="app"', false);
    }
}
