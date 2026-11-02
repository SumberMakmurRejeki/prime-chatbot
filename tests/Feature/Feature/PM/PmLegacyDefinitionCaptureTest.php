<?php

namespace Tests\Feature\Feature\PM;

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetPart;
use App\Models\PmChecksheetStandard;
use App\Models\PmExecution;
use App\Models\PmExecutionItem;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\PM\PmExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PmLegacyDefinitionCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected Machine $machine;

    protected PmChecksheetPart $part;

    protected PmChecksheetStandard $actionStandard;

    protected PmChecksheetStandard $numberStandard;

    protected PmScheduleDate $scheduleDate;

    protected PmExecution $execution;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $location = Location::query()->create([
            'location_code' => 'LOC-LGC',
            'location_name' => 'Upcast',
            'is_active' => true,
        ]);

        $this->machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'UC-LGC',
            'machine_name' => 'MOTOR SERVO LGC',
            'qr_token' => 'qr-uc-lgc',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);

        $this->admin = User::query()->create([
            'name' => 'Admin Legacy',
            'username' => 'admin.legacy',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->operator = User::query()->create([
            'name' => 'Operator Legacy',
            'username' => 'operator.legacy',
            'password' => Hash::make('password'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-UC-LGC',
            'checksheet_name' => 'Checksheet UC-LGC',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
            'created_by' => $this->admin->id,
        ]);

        $this->part = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'part_name' => 'Coiling Legacy',
            'is_active' => true,
        ]);

        $this->actionStandard = PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $this->part->id,
            'standard_name' => 'Kondisi Gearbox',
            'input_type' => 'action',
            'action_options' => ['OK', 'GANTI'],
            'is_required' => true,
            'is_active' => true,
        ]);

        $this->numberStandard = PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $this->part->id,
            'standard_name' => 'Suhu Motor',
            'input_type' => 'number',
            'target_value' => 60,
            'unit' => 'celcius',
            'is_required' => true,
            'is_active' => true,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'operational_from' => now()->subDay()->toDateString(),
            'frequency_type' => 'daily',
            'start_date' => now()->subDay()->toDateString(),
            'generate_until' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->scheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
            'status_changed_at' => now(),
        ]);

        $this->execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ]);
    }

    public function test_dry_run_reports_plan_without_writing(): void
    {
        $items = $this->createLegacyItems();

        $report = app(PmExecutionService::class)->captureLegacyDefinitions($this->execution->id, false);

        $this->assertFalse($report['applied']);
        $this->assertFalse($report['skipped']);
        $this->assertSame([], $report['conflicts']);
        $this->assertSame(0, $report['updated']);

        $this->assertNull($this->execution->fresh()->definition_snapshot_at);
        $this->assertSame('Kondisi Gearbox Lama', $items['action']->fresh()->standard_name_snapshot);
        $this->assertNull($items['number']->fresh()->action_options_snapshot);
        $this->assertSame(['OK', 'GANTI'], $items['action']->fresh()->action_options_snapshot);
    }

    public function test_apply_preserves_existing_historical_definitions_results_and_ids(): void
    {
        $items = $this->createLegacyItems();
        $idsBefore = PmExecutionItem::query()->where('pm_execution_id', $this->execution->id)->orderBy('id')->pluck('id')->all();

        // Hasil operator sudah tersimpan sebelum capture.
        $items['action']->forceFill(['action_value' => 'OK'])->save();
        $items['number']->forceFill(['number_value' => 55, 'is_warning' => true, 'warning_message' => 'Lama', 'note' => 'Catatan lama'])->save();

        // Master berubah setelah hasil masuk, tetapi bukan sumber snapshot legacy.
        $this->part->forceFill(['part_name' => 'Coiling Baru'])->save();
        $this->actionStandard->forceFill([
            'standard_name' => 'Kondisi Gearbox Baru',
            'action_options' => ['OK', 'TAMBAH OLI'],
            'is_required' => false,
        ])->save();
        $this->numberStandard->forceFill(['target_value' => 99, 'unit' => 'kelvin'])->save();

        $report = app(PmExecutionService::class)->captureLegacyDefinitions($this->execution->id, true);

        $this->assertTrue($report['applied']);
        $this->assertSame([], $report['conflicts']);
        $this->assertNotNull($this->execution->fresh()->definition_snapshot_at);

        $idsAfter = PmExecutionItem::query()->where('pm_execution_id', $this->execution->id)->orderBy('id')->pluck('id')->all();
        $this->assertSame($idsBefore, $idsAfter);

        $actionItem = $items['action']->fresh();
        $this->assertSame('Coiling Legacy', $actionItem->part_name_snapshot);
        $this->assertSame('Kondisi Gearbox Lama', $actionItem->standard_name_snapshot);
        $this->assertSame(['OK', 'GANTI'], $actionItem->action_options_snapshot);
        $this->assertTrue((bool) $actionItem->is_required_snapshot);
        $this->assertSame('OK', $actionItem->action_value);

        $numberItem = $items['number']->fresh();
        $this->assertSame('Suhu Motor Lama', $numberItem->standard_name_snapshot);
        $this->assertSame(60.0, (float) $numberItem->target_value_snapshot);
        $this->assertSame('celcius', $numberItem->unit_snapshot);
        $this->assertSame(55.0, (float) $numberItem->number_value);
        $this->assertTrue((bool) $numberItem->is_warning);
        $this->assertSame('Lama', $numberItem->warning_message);
        $this->assertSame('Catatan lama', $numberItem->note);

        // Run berikutnya dilewati karena marker sudah terisi.
        $second = app(PmExecutionService::class)->captureLegacyDefinitions($this->execution->id, true);
        $this->assertTrue($second['skipped']);
        $this->assertFalse($second['applied']);
        $this->assertSame($idsAfter, PmExecutionItem::query()->where('pm_execution_id', $this->execution->id)->orderBy('id')->pluck('id')->all());
    }

    public function test_incomplete_existing_snapshot_fails_closed_without_master_recovery(): void
    {
        $items = $this->createLegacyItems();
        $items['action']->forceFill(['action_options_snapshot' => []])->save();

        $report = app(PmExecutionService::class)->captureLegacyDefinitions($this->execution->id, true);

        $this->assertFalse($report['applied']);
        $this->assertNotEmpty($report['conflicts']);
        $this->assertNull($this->execution->fresh()->definition_snapshot_at);
        $this->assertSame([], $items['action']->fresh()->action_options_snapshot);
        $this->assertSame('Kondisi Gearbox Lama', $items['action']->fresh()->standard_name_snapshot);
    }

    public function test_action_value_outside_frozen_options_is_a_conflict(): void
    {
        $items = $this->createLegacyItems();
        $items['action']->forceFill(['action_value' => 'REPAIR'])->save();

        // Validasi memakai opsi snapshot historical, bukan Master yang dapat berubah.

        $report = app(PmExecutionService::class)->captureLegacyDefinitions($this->execution->id, true);

        $this->assertFalse($report['applied']);
        $this->assertCount(1, $report['conflicts']);
        $this->assertNull($this->execution->fresh()->definition_snapshot_at);
        $this->assertSame(['OK', 'GANTI'], $items['action']->fresh()->action_options_snapshot);
        $this->assertSame('Kondisi Gearbox Lama', $items['action']->fresh()->standard_name_snapshot);
    }

    public function test_execution_outside_executor_path_is_skipped_without_mutation(): void
    {
        $items = $this->createLegacyItems();
        $this->execution->forceFill(['status' => 'waiting_review', 'submitted_at' => now()])->save();

        $report = app(PmExecutionService::class)->captureLegacyDefinitions($this->execution->id, true);

        $this->assertTrue($report['skipped']);
        $this->assertFalse($report['applied']);
        $this->assertNull($this->execution->fresh()->definition_snapshot_at);
        $this->assertSame('Kondisi Gearbox Lama', $items['action']->fresh()->standard_name_snapshot);
    }

    public function test_zero_item_legacy_execution_recovers_current_master_definition(): void
    {
        $dryRun = app(PmExecutionService::class)->captureLegacyDefinitions($this->execution->id, false);

        $this->assertFalse($dryRun['applied']);
        $this->assertSame([], $dryRun['conflicts']);
        $this->assertSame(2, $dryRun['inserted']);
        $this->assertSame(0, PmExecutionItem::query()->count());
        $this->assertNull($this->execution->fresh()->definition_snapshot_at);

        $apply = app(PmExecutionService::class)->captureLegacyDefinitions($this->execution->id, true);

        $this->assertTrue($apply['applied']);
        $this->assertSame(2, $apply['inserted']);
        $this->assertNotNull($this->execution->fresh()->definition_snapshot_at);
        $this->assertSame(2, PmExecutionItem::query()->count());
        $this->assertDatabaseHas('pm_execution_items', [
            'pm_execution_id' => $this->execution->id,
            'standard_name_snapshot' => 'Kondisi Gearbox',
        ]);
    }

    public function test_command_defaults_to_dry_run_and_apply_preserves_existing_items(): void
    {
        $items = $this->createLegacyItems();

        $dryRun = $this->artisan('pm:capture-legacy-execution-definitions');
        $dryRun->expectsOutputToContain('"mode":"dry-run"');
        $dryRun->assertExitCode(0);
        $dryRun->run();

        $this->assertNull($this->execution->fresh()->definition_snapshot_at);
        $this->assertSame('Kondisi Gearbox Lama', $items['action']->fresh()->standard_name_snapshot);

        $apply = $this->artisan('pm:capture-legacy-execution-definitions', ['--apply' => true]);
        $apply->expectsOutputToContain('"mode":"apply"');
        $apply->assertExitCode(0);
        $apply->run();

        $this->assertNotNull($this->execution->fresh()->definition_snapshot_at);
        $this->assertSame('Kondisi Gearbox Lama', $items['action']->fresh()->standard_name_snapshot);
    }

    public function test_command_returns_failure_when_conflicts_exist(): void
    {
        $items = $this->createLegacyItems();
        $items['action']->forceFill(['action_value' => 'REPAIR'])->save();
        $this->actionStandard->forceFill(['action_options' => ['OK', 'GANTI']])->save();

        $exit = $this->artisan('pm:capture-legacy-execution-definitions', [
            '--execution-id' => [$this->execution->id],
            '--apply' => true,
        ]);
        $exit->expectsOutputToContain('"conflicts":1');
        $exit->assertExitCode(1);
        $exit->run();

        $this->assertNull($this->execution->fresh()->definition_snapshot_at);
    }

    /**
     * Baris hasil gaya legacy: snapshot kolom nama tetap terisi, tetapi marker
     * definisi dan snapshot opsi/required belum ada.
     *
     * @return array<string, PmExecutionItem>
     */
    private function createLegacyItems(): array
    {
        return [
            'action' => PmExecutionItem::query()->create([
                'pm_execution_id' => $this->execution->id,
                'pm_checksheet_part_id' => $this->part->id,
                'pm_checksheet_standard_id' => $this->actionStandard->id,
                'part_name_snapshot' => 'Coiling Legacy',
                'standard_name_snapshot' => 'Kondisi Gearbox Lama',
                'input_type_snapshot' => 'action',
                'action_options_snapshot' => ['OK', 'GANTI'],
                'is_required_snapshot' => true,
            ]),
            'number' => PmExecutionItem::query()->create([
                'pm_execution_id' => $this->execution->id,
                'pm_checksheet_part_id' => $this->part->id,
                'pm_checksheet_standard_id' => $this->numberStandard->id,
                'part_name_snapshot' => 'Coiling Legacy',
                'standard_name_snapshot' => 'Suhu Motor Lama',
                'input_type_snapshot' => 'number',
                'target_value_snapshot' => 60,
                'unit_snapshot' => 'celcius',
                'is_required_snapshot' => true,
            ]),
        ];
    }
}
