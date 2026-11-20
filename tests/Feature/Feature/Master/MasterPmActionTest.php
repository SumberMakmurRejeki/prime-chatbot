<?php

namespace Tests\Feature\Feature\Master;

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmAction;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetPart;
use App\Models\PmChecksheetStandard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MasterPmActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected PmChecksheetStandard $standard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Admin PRIME',
            'username' => 'admin.prime',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->operator = User::query()->create([
            'name' => 'Operator PRIME',
            'username' => 'operator.prime',
            'password' => Hash::make('password'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $location = Location::query()->create([
            'location_code' => 'LOC-01',
            'location_name' => 'Line 1',
            'is_active' => true,
        ]);

        $machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-01',
            'machine_name' => 'Sealing Machine',
            'qr_token' => 'qr-mc-01',
            'is_active' => true,
        ]);

        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-01',
            'checksheet_name' => 'Checksheet MC-01',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $machine->id,
            'created_by' => $this->admin->id,
        ]);

        $part = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'part_name' => 'Part 1',
            'is_active' => true,
        ]);

        $this->standard = PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $part->id,
            'standard_name' => 'Kondisi Gearbox',
            'input_type' => 'action',
            'action_options' => ['OK', 'REPAIR'],
            'is_required' => true,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_open_master_action_page_and_others_are_denied(): void
    {
        $action = PmAction::query()->create(['action_label' => 'REPAIR']);

        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Action/Index')
                ->where('actions.data.0.id', $action->id)
                ->where('actions.data.0.action_label', 'REPAIR')
                ->where('actions.data.0.is_active', true)
                ->where('filters.search', '')
                ->has('actions.links')
                ->has('notificationEndpoints'));

        $this->actingAs($this->operator)
            ->get('/pm/master-checksheet/actions')
            ->assertRedirect('/403');
    }

    public function test_master_action_page_form_creates_action_and_redirects_with_flash_message(): void
    {
        $this->actingAs($this->admin)
            ->post('/pm/master-checksheet/actions', ['action_label' => ' cek   oli '])
            ->assertRedirect('/pm/master-checksheet/actions')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash_success');

        $this->assertDatabaseHas('pm_actions', ['action_label' => 'CEK OLI']);

        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Action/Index')
                ->where('actions.data.0.action_label', 'CEK OLI')
                ->where('flash.success', 'Master Action berhasil dibuat.'));

        $this->actingAs($this->operator)
            ->get('/pm/master-checksheet/actions')
            ->assertRedirect('/403');
    }

    public function test_master_action_page_keeps_comma_label_as_one_action(): void
    {
        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Action/Index')
                ->where('filters.search', '')
                ->where('storeUrl', url('/pm/master-checksheet/actions')));

        $this->actingAs($this->admin)
            ->post('/pm/master-checksheet/actions', ['action_label' => 'OK, NG, TAMBAH OLI'])
            ->assertRedirect('/pm/master-checksheet/actions')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('pm_actions', 1);
        $this->assertDatabaseHas('pm_actions', ['action_label' => 'OK, NG, TAMBAH OLI']);
    }

    /**
     * Kontrak props Inertia: pencarian tetap dieksekusi server dan hanya baris
     * yang cocok yang dikirim ke halaman Vue.
     */
    public function test_master_action_page_filters_actions_on_the_server(): void
    {
        PmAction::query()->create(['action_label' => 'GANTI BEARING']);
        PmAction::query()->create(['action_label' => 'TAMBAH OLI']);

        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions?search=bearing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Action/Index')
                ->where('filters.search', 'bearing')
                ->has('actions.data', 1)
                ->where('actions.data.0.action_label', 'GANTI BEARING'));
    }

    public function test_master_action_status_defaults_to_all_and_invalid_values_are_normalized(): void
    {
        $active = PmAction::query()->create(['action_label' => 'ACTIVE', 'is_active' => true]);
        $inactive = PmAction::query()->create(['action_label' => 'INACTIVE', 'is_active' => false]);

        foreach (['', 'all', 'unexpected'] as $status) {
            $url = '/pm/master-checksheet/actions'.($status === '' ? '' : '?status='.$status);
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Master/Action/Index')
                    ->where('filters.search', '')
                    ->where('filters.status', 'all')
                    ->where('actions.total', 2)
                    ->where('actions.data.0.id', $active->id)
                    ->where('actions.data.0.is_active', true)
                    ->where('actions.data.1.id', $inactive->id)
                    ->where('actions.data.1.is_active', false));
        }

        // Parameter status tanpa nilai (mis. tautan lama) juga harus diperlakukan
        // sebagai 'Semua', bukan men-trigger error.
        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions?status=')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'all')
                ->where('actions.total', 2));
    }

    public function test_master_action_status_filters_active_and_inactive_with_search(): void
    {
        $activeMatch = PmAction::query()->create(['action_label' => 'BEARING ACTIVE', 'is_active' => true]);
        PmAction::query()->create(['action_label' => 'BEARING INACTIVE', 'is_active' => false]);
        PmAction::query()->create(['action_label' => 'OTHER ACTIVE', 'is_active' => true]);
        $inactiveMatch = PmAction::query()->create(['action_label' => 'FILTER INACTIVE', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions?search=bearing&status=active')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'bearing')
                ->where('filters.status', 'active')
                ->where('actions.total', 1)
                ->where('actions.data.0.id', $activeMatch->id)
                ->where('actions.data.0.action_label', 'BEARING ACTIVE')
                ->where('actions.data.0.is_active', true));

        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions?search=filter&status=inactive')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'filter')
                ->where('filters.status', 'inactive')
                ->where('actions.total', 1)
                ->where('actions.data.0.id', $inactiveMatch->id)
                ->where('actions.data.0.action_label', 'FILTER INACTIVE')
                ->where('actions.data.0.is_active', false));

        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions?search=missing&status=active')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'active')
                ->where('actions.total', 0)
                ->has('actions.data', 0));
    }

    public function test_master_action_status_pagination_retains_status_and_search(): void
    {
        foreach (range(1, 11) as $number) {
            PmAction::query()->create(['action_label' => sprintf('PAGE FILTER %02d', $number), 'is_active' => true]);
        }
        PmAction::query()->create(['action_label' => 'PAGE FILTER INACTIVE', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get('/pm/master-checksheet/actions?search=PAGE%20FILTER&status=active&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'PAGE FILTER')
                ->where('filters.status', 'active')
                ->where('actions.current_page', 2)
                ->where('actions.total', 11)
                ->where('actions.data.0.action_label', 'PAGE FILTER 11')
                ->where('actions.data.0.is_active', true)
                ->where('actions.prev_page_url', fn (string $url): bool => str_contains($url, 'search=PAGE%20FILTER') && str_contains($url, 'status=active')));
    }

    public function test_non_admin_cannot_open_master_action_with_status_filter(): void
    {
        $this->actingAs($this->operator)
            ->get('/pm/master-checksheet/actions?search=restricted&status=inactive')
            ->assertRedirect('/403');
    }

    public function test_master_action_page_form_rejects_blank_label_with_redirect(): void
    {
        $this->actingAs($this->admin)
            ->from('/pm/master-checksheet/actions')
            ->post('/pm/master-checksheet/actions', ['action_label' => '   '])
            ->assertRedirect('/pm/master-checksheet/actions')
            ->assertSessionHasErrors('action_label');

        $this->assertDatabaseCount('pm_actions', 0);
    }

    public function test_master_action_page_shows_flash_error_when_deactivation_would_empty_active_standard(): void
    {
        $action = PmAction::query()->create(['action_label' => 'REPAIR']);
        $this->standard->actions()->sync([$action->id => ['position' => 1]]);

        $this->actingAs($this->admin)
            ->patch("/pm/master-checksheet/actions/{$action->id}/nonaktifkan")
            ->assertRedirect('/pm/master-checksheet/actions')
            ->assertSessionHas('flash_error');

        $this->assertTrue($action->fresh()->is_active);
    }

    public function test_create_normalizes_label_and_defaults_to_active_with_audit(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/pm/master-checksheet/actions', ['action_label' => '  ganti   bearing ']);

        $response->assertCreated()->assertJson([
            'action_label' => 'GANTI BEARING',
            'is_active' => true,
        ]);

        $action = PmAction::query()->firstOrFail();
        $this->assertSame('GANTI BEARING', $action->action_label);
        $this->assertTrue($action->is_active);
        $this->assertDatabaseHas('user_activity_logs', [
            'module_name' => 'master_action',
            'action' => 'create',
            'table_name' => 'pm_actions',
            'record_id' => $action->id,
        ]);
    }

    public function test_create_rejects_blank_and_overlength_labels(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/pm/master-checksheet/actions', ['action_label' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action_label');

        $this->actingAs($this->admin)
            ->postJson('/pm/master-checksheet/actions', ['action_label' => str_repeat('A', 51)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action_label');

        $this->assertDatabaseCount('pm_actions', 0);
    }

    public function test_duplicate_canonical_label_is_rejected_even_with_different_spacing_and_case(): void
    {
        PmAction::query()->create(['action_label' => 'GANTI BEARING']);

        $this->actingAs($this->admin)
            ->postJson('/pm/master-checksheet/actions', ['action_label' => 'ganti  bearing'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action_label');

        $this->assertDatabaseCount('pm_actions', 1);
    }

    public function test_update_renames_label_and_records_old_and_new_values(): void
    {
        $action = PmAction::query()->create(['action_label' => 'REPAIR LAMA']);

        $this->actingAs($this->admin)
            ->patch('/pm/master-checksheet/actions/'.$action->id, ['action_label' => 'repair baru'])
            ->assertRedirect('/pm/master-checksheet/actions');

        $this->assertSame('REPAIR BARU', $action->fresh()->action_label);

        $log = DB::table('user_activity_logs')->where('module_name', 'master_action')->where('action', 'update')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('REPAIR LAMA', (string) $log->old_values);
        $this->assertStringContainsString('REPAIR BARU', (string) $log->new_values);
    }

    public function test_deactivation_is_rejected_when_it_would_empty_an_active_action_standard(): void
    {
        $onlyAction = PmAction::query()->create(['action_label' => 'OK']);
        $this->standard->actions()->attach($onlyAction->id, ['position' => 1]);

        $this->actingAs($this->admin)
            ->patch('/pm/master-checksheet/actions/'.$onlyAction->id.'/nonaktifkan')
            ->assertRedirect('/pm/master-checksheet/actions')
            ->assertSessionHas('flash_error');

        $this->assertTrue($onlyAction->fresh()->is_active);
    }

    public function test_deactivation_is_allowed_when_other_active_option_remains(): void
    {
        $first = PmAction::query()->create(['action_label' => 'OK']);
        $second = PmAction::query()->create(['action_label' => 'REPAIR']);
        $this->standard->actions()->attach($first->id, ['position' => 1]);
        $this->standard->actions()->attach($second->id, ['position' => 2]);

        $this->actingAs($this->admin)
            ->patch('/pm/master-checksheet/actions/'.$second->id.'/nonaktifkan')
            ->assertRedirect('/pm/master-checksheet/actions')
            ->assertSessionHas('flash_success');

        $this->assertFalse($second->fresh()->is_active);
        $this->assertTrue($first->fresh()->is_active);
    }

    public function test_deactivation_of_unmapped_action_is_allowed_and_reactivation_restores_it(): void
    {
        $action = PmAction::query()->create(['action_label' => 'BEBAS']);

        $this->actingAs($this->admin)
            ->patch('/pm/master-checksheet/actions/'.$action->id.'/nonaktifkan')
            ->assertSessionHas('flash_success');
        $this->assertFalse($action->fresh()->is_active);

        $this->actingAs($this->admin)
            ->patch('/pm/master-checksheet/actions/'.$action->id.'/aktifkan')
            ->assertSessionHas('flash_success');
        $this->assertTrue($action->fresh()->is_active);
    }

    public function test_no_destructive_delete_route_is_registered(): void
    {
        $action = PmAction::query()->create(['action_label' => 'AMAN']);

        $this->actingAs($this->admin)
            ->delete('/pm/master-checksheet/actions/'.$action->id)
            ->assertStatus(405);

        $this->assertDatabaseHas('pm_actions', ['id' => $action->id]);
    }

    public function test_backfill_dry_run_reports_plan_without_writing(): void
    {
        $before = $this->standard->getRawOriginal('action_options');

        $exitCode = Artisan::call('pm:backfill-master-actions');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"mode":"dry-run"', $output);
        $this->assertStringContainsString('"distinct_canonical_labels":2', $output);
        $this->assertStringContainsString('"planned_mappings":2', $output);
        $this->assertDatabaseCount('pm_actions', 0);
        $this->assertDatabaseCount('pm_checksheet_standard_actions', 0);
        $this->assertSame($before, $this->standard->fresh()->getRawOriginal('action_options'));
    }

    public function test_backfill_apply_is_idempotent_and_preserves_legacy_json(): void
    {
        DB::table('pm_checksheet_standards')->where('id', $this->standard->id)->update([
            'action_options' => json_encode(['  ok ', 'repair', 'OK']),
        ]);
        $before = PmChecksheetStandard::query()->whereKey($this->standard->id)->value('action_options');

        $this->assertSame(0, Artisan::call('pm:backfill-master-actions', ['--apply' => true]));
        $this->assertSame(2, PmAction::query()->count());
        $this->assertDatabaseHas('pm_checksheet_standard_actions', [
            'pm_checksheet_standard_id' => $this->standard->id,
            'pm_action_id' => PmAction::query()->where('action_label', 'OK')->value('id'),
            'position' => 1,
        ]);
        $this->assertDatabaseHas('pm_checksheet_standard_actions', [
            'pm_checksheet_standard_id' => $this->standard->id,
            'pm_action_id' => PmAction::query()->where('action_label', 'REPAIR')->value('id'),
            'position' => 2,
        ]);
        $this->assertSame($before, PmChecksheetStandard::query()->whereKey($this->standard->id)->value('action_options'));

        $this->assertSame(0, Artisan::call('pm:backfill-master-actions', ['--apply' => true]));
        $this->assertSame(2, PmAction::query()->count());
        $this->assertDatabaseCount('pm_checksheet_standard_actions', 2);
    }

    public function test_backfill_rejects_malformed_standard_and_writes_nothing(): void
    {
        DB::table('pm_checksheet_standards')->where('id', $this->standard->id)->update([
            'action_options' => json_encode(['OK', '   ']),
        ]);

        $exitCode = Artisan::call('pm:backfill-master-actions', ['--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('entri_kosong', $output);
        $this->assertDatabaseCount('pm_actions', 0);
        $this->assertDatabaseCount('pm_checksheet_standard_actions', 0);
    }
}
