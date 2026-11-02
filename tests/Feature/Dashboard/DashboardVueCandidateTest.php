<?php

namespace Tests\Feature\Dashboard;

use App\Models\GuestSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardVueCandidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_receives_dashboard_candidate_and_matching_default_period(): void
    {
        $admin = $this->createUser('admin', true);

        $response = $this->actingAs($admin)->get('/internal/dashboard-vue');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('auth.name', 'admin PRIME')
            ->where('auth.role', 'admin')
            ->where('dateRange.start', now()->startOfMonth()->toDateString())
            ->where('dateRange.end', now()->toDateString())
            ->where('defaultDateRange.start', now()->startOfMonth()->toDateString())
            ->where('defaultDateRange.end', now()->toDateString())
            ->has('pmRateSeries')
            ->has('notificationEndpoints.index'));
    }

    public function test_candidate_accepts_valid_range_and_rejects_invalid_range_like_blade(): void
    {
        $admin = $this->createUser('admin', true);

        $this->actingAs($admin)
            ->get('/internal/dashboard-vue?start_date=2026-06-10&end_date=2026-06-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dateRange.start', '2026-06-10')
                ->where('dateRange.end', '2026-06-30'));

        $this->actingAs($admin)
            ->get('/internal/dashboard-vue?start_date=2026-06-30&end_date=2026-06-10')
            ->assertRedirect()
            ->assertSessionHasErrors('end_date');
    }

    /**
     * Kandidat dan Blade harus memakai kontrak data server yang sama:
     * built-in Inertia page props dibandingkan langsung dengan viewData Blade
     * untuk filter (periode) dan dataset yang identik.
     */
    public function test_production_and_verification_routes_share_identical_dashboard_props(): void
    {
        $admin = $this->createUser('admin', true);

        $productionResponse = $this->actingAs($admin)->get('/dashboard?start_date=2026-06-01&end_date=2026-06-30');
        $productionResponse->assertOk();

        $candidateResponse = $this->get('/internal/dashboard-vue?start_date=2026-06-01&end_date=2026-06-30');
        $candidateResponse->assertOk();

        $productionPage = $productionResponse->viewData('page');
        $candidatePage = $candidateResponse->viewData('page');

        $this->assertSame('Dashboard', $productionPage['component']);
        $this->assertSame($productionPage['component'], $candidatePage['component']);
        unset($productionPage['props']['errors'], $candidatePage['props']['errors']);
        $this->assertEquals($productionPage['props'], $candidatePage['props']);
        $this->assertSame('dashboard', app('router')->getRoutes()->getByName('dashboard')->uri());
        $this->assertSame('internal/dashboard-vue', app('router')->getRoutes()->getByName('internal.dashboard-vue')->uri());
    }

    public function test_candidate_keeps_server_default_period_on_filtered_visit(): void
    {
        $admin = $this->createUser('admin', true);

        $this->actingAs($admin)
            ->get('/internal/dashboard-vue?start_date=2026-05-01&end_date=2026-05-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dateRange.start', '2026-05-01')
                ->where('dateRange.end', '2026-05-31')
                ->where('defaultDateRange.start', now()->startOfMonth()->toDateString())
                ->where('defaultDateRange.end', now()->toDateString()));
    }

    public function test_guest_receives_dashboard_with_guest_identity_and_no_admin_capability(): void
    {
        $guestSession = GuestSession::query()->create([
            'guest_name' => 'Guest PRIME',
            'session_id' => 'candidate-guest-session',
            'login_at' => now(),
        ]);

        $response = $this->withSession([
            'guest_session_id' => $guestSession->id,
            'guest_name' => $guestSession->guest_name,
        ])->get('/dashboard');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('auth.name', 'Guest PRIME')
            ->where('auth.role', 'guest')
            ->where('manageAi', false)
            ->where('notificationEndpoints', null));
    }

    public function test_operator_is_denied_and_production_route_is_inertia_dashboard(): void
    {
        $operator = $this->createUser('operator', true);
        $admin = $this->createUser('admin', true);

        $this->actingAs($operator)->get('/internal/dashboard-vue')->assertRedirect('/403');
        $this->actingAs($operator)->get('/dashboard')->assertRedirect('/403');
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('notificationEndpoints.index'));
    }

    public function test_candidate_is_not_available_outside_approved_environments(): void
    {
        $admin = $this->createUser('admin', true);

        $this->app['config']->set('app.env', 'production');

        $this->actingAs($admin)->get('/internal/dashboard-vue')->assertNotFound();
    }

    protected function createUser(string $role, bool $active): User
    {
        return User::query()->create([
            'name' => $role.' PRIME',
            'username' => $role.'.candidate',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => $active,
        ]);
    }
}
