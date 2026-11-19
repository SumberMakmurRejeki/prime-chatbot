<?php

namespace Tests\Feature\Feature\PM;

use App\Models\GuestSession;
use App\Models\Location;
use App\Models\Machine;
use App\Models\PmAction;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetPart;
use App\Models\PmChecksheetStandard;
use App\Models\PmExecution;
use App\Models\PmExecutionItem;
use App\Models\PmExecutionMedia;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\Auth\ActivityLogService;
use App\Services\Master\PmActionService;
use App\Services\Notification\AdminNotificationService;
use App\Services\PM\BusinessDate;
use App\Services\PM\LifecyclePolicy;
use App\Services\PM\MachineLifecycleService;
use App\Services\PM\PmExecutionHistoryService;
use App\Services\PM\PmExecutionMediaService;
use App\Services\PM\PmExecutionService;
use App\Services\PM\PmScheduleDateReconciler;
use App\Services\PM\PmWarningService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PmExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected Machine $machine;

    protected PmScheduleDate $scheduleDate;

    protected PmChecksheetPart $part;

    protected PmChecksheetStandard $actionStandard;

    protected PmChecksheetStandard $numberStandard;

    protected PmChecksheetStandard $rangeStandard;

    protected function tearDown(): void
    {
        // Mengembalikan waktu Carbon agar fixture test lain tidak terpengaruh.
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $location = Location::query()->create([
            'location_code' => 'LOC-01',
            'location_name' => 'Upcast',
            'is_active' => true,
        ]);

        $this->machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'UC-001',
            'machine_name' => 'MOTOR SERVO',
            'qr_token' => 'qr-uc-001',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);

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

        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-UC-001',
            'checksheet_name' => 'Checksheet UC-001',
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
            'part_name' => 'Coiling 1',
            'is_active' => true,
        ]);

        $this->actionStandard = PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $this->part->id,
            'standard_name' => 'Kondisi Gearbox',
            'input_type' => 'action',
            'action_options' => ['OK', 'GANTI', 'REPAIR'],
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

        $this->rangeStandard = PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $this->part->id,
            'standard_name' => 'Tekanan',
            'input_type' => 'range',
            'min_value' => 114,
            'max_value' => 190,
            'unit' => 'bar',
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
            'status' => 'scheduled',
        ]);
    }

    public function test_inactive_checksheet_rejects_direct_start_without_creating_execution(): void
    {
        $checksheet = PmChecksheet::query()->firstOrFail();
        $this->actingAs($this->admin)
            ->patch("/pm/master-checksheet/{$checksheet->id}/nonaktifkan")
            ->assertRedirect('/pm/master-checksheet');

        $this->actingAs($this->operator)
            ->from("/pm/executor/{$this->machine->machine_code}")
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}")
            ->assertSessionHasErrors([
                'machine' => 'Checksheet nonaktif. PM baru tidak dapat dimulai.',
            ]);

        $this->assertDatabaseCount('pm_executions', 0);
        $this->assertDatabaseCount('pm_execution_items', 0);
        $this->assertSame('scheduled', $this->scheduleDate->fresh()->status);
        $this->assertFalse($checksheet->fresh()->is_active);
    }

    public function test_start_before_checksheet_deactivation_preserves_continuation_review_and_snapshots(): void
    {
        $checksheet = PmChecksheet::query()->firstOrFail();
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertSessionHasNoErrors();
        $execution = PmExecution::query()->firstOrFail();
        $snapshotColumns = [
            'id', 'pm_checksheet_part_id', 'pm_checksheet_standard_id',
            'part_name_snapshot', 'standard_name_snapshot', 'input_type_snapshot',
            'selection_mode_snapshot', 'action_options_snapshot', 'target_value_snapshot',
            'min_value_snapshot', 'max_value_snapshot', 'unit_snapshot', 'is_required_snapshot',
        ];
        $snapshots = $execution->items()->orderBy('id')->get($snapshotColumns)->toArray();
        $history = DB::table('pm_execution_history')->where('pm_execution_id', $execution->id)->get()->toArray();

        $this->actingAs($this->admin)
            ->patch("/pm/master-checksheet/{$checksheet->id}/nonaktifkan")
            ->assertSessionHasNoErrors();
        $this->assertEquals(
            $history,
            DB::table('pm_execution_history')->where('pm_execution_id', $execution->id)->get()->toArray(),
        );

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertSessionHasNoErrors();
        $this->assertSame($execution->id, PmExecution::query()->sole()->id);

        $results = [
            'execution_action' => [$this->itemIdFor($this->actionStandard) => 'OK'],
            'execution_number' => [
                $this->itemIdFor($this->numberStandard) => 60,
                $this->itemIdFor($this->rangeStandard) => 120,
            ],
        ];
        $this->post("/pm/executor/{$this->machine->machine_code}/draft", [
            ...$results,
            'expected_revision' => (int) $execution->fresh()->revision,
        ])->assertRedirect("/pm/executor/{$this->machine->machine_code}")->assertSessionHasNoErrors();
        $this->assertSame(60.0, (float) PmExecutionItem::query()->findOrFail($this->itemIdFor($this->numberStandard))->number_value);

        $this->post("/pm/executor/{$this->machine->machine_code}/submit", [
            ...$results,
            'expected_revision' => (int) $execution->fresh()->revision,
        ])->assertRedirect("/machines/{$this->machine->machine_code}")->assertSessionHasNoErrors();
        $this->assertSame('waiting_review', $execution->fresh()->status);

        $this->actingAs($this->admin)
            ->patch("/pm/review/{$execution->id}/approve", [
                'expected_revision' => (int) $execution->fresh()->revision,
                'review_note' => 'Pekerjaan yang sudah dimulai tetap dapat diselesaikan.',
            ])
            ->assertRedirect("/pm/review/{$execution->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $execution->fresh()->status);
        $this->assertSame('approved', $this->scheduleDate->fresh()->status);
        $this->assertSame($snapshots, $execution->items()->orderBy('id')->get($snapshotColumns)->toArray());
        $this->assertFalse($checksheet->fresh()->is_active);
        $this->assertDatabaseCount('pm_executions', 1);
        $this->assertDatabaseHas('pm_execution_history', [
            'pm_execution_id' => $execution->id,
            'field_name' => 'Status PM',
            'old_value' => 'waiting_review',
            'new_value' => 'approved',
        ]);
    }

    public function test_start_guard_preserves_admin_operator_authorization(): void
    {
        $checksheet = PmChecksheet::query()->firstOrFail();
        $this->actingAs($this->admin)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertRedirect('/403');
        $this->actingAs($this->operator)
            ->patch("/pm/master-checksheet/{$checksheet->id}/nonaktifkan")
            ->assertRedirect('/403');

        $this->assertTrue($checksheet->fresh()->is_active);
        $this->assertDatabaseCount('pm_executions', 0);
    }

    /**
     * TASK-006 Slice C: satu assignment hanya boleh memiliki satu era schedule
     * current (active/paused). Test yang membuat era schedule baru harus
     * mengakhiri era fixture lebih dahulu agar invariant database tetap utuh.
     */
    protected function endFixtureScheduleEra(): void
    {
        // Proyeksi kompatibilitas is_active ditulis pada operasi persist yang
        // sama dengan lifecycle_status, mengikuti kontrak ScheduleLifecycleService.
        PmSchedule::query()
            ->whereKey($this->scheduleDate->pm_schedule_id)
            ->update([
                'lifecycle_status' => 'ended',
                'is_active' => false,
            ]);
    }

    /**
     * TASK-006 Slice C (koreksi B-1): membuat era schedule + occurrence untuk
     * satu mesin. Dipakai untuk membangun dua era berbeda pada mesin yang sama;
     * assignment diisi null untuk membuat assignment baru.
     */
    private function createEraOccurrence(
        Machine $machine,
        string $suffix,
        ?int $assignmentId,
        array $scheduleAttributes,
        string $scheduledDate,
        string $status = 'scheduled',
    ): PmScheduleDate {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => "PM-CH-ERA-{$suffix}",
            'checksheet_name' => "Checksheet ERA {$suffix}",
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $machine->id,
            'created_by' => $this->admin->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignmentId ?? $assignment->id,
            'frequency_type' => 'daily',
            'operational_from' => $scheduleAttributes['operational_from'] ?? now()->toDateString(),
            'start_date' => $scheduleAttributes['start_date'] ?? now()->toDateString(),
            'generate_until' => $scheduleAttributes['generate_until'] ?? now()->addMonth()->toDateString(),
            'is_active' => $scheduleAttributes['is_active'] ?? true,
            'lifecycle_status' => $scheduleAttributes['lifecycle_status'] ?? 'active',
            'created_by' => $this->admin->id,
        ]);

        return PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => $scheduledDate,
            'status' => $status,
        ]);
    }

    /**
     * Schedule PAUSED bukan kandidat start baru: occurrence era paused yang
     * lebih awal tidak boleh dipilih di depan era active berikutnya.
     */
    public function test_active_era_occurrence_is_selected_over_paused_era_occurrence(): void
    {
        // Era fixture di-pause; occurrence-nya (hari ini) lebih awal dari era baru.
        PmSchedule::query()->whereKey($this->scheduleDate->pm_schedule_id)->update([
            'lifecycle_status' => 'paused',
            'is_active' => false,
        ]);

        // Era active baru pada assignment lain untuk mesin yang sama.
        $activeDate = $this->createEraOccurrence(
            $this->machine,
            'ACT-01',
            null,
            [],
            now()->toDateString(),
        );

        $part = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $activeDate->schedule->pm_checksheet_machine_id,
            'part_name' => 'Part ERA ACT-01',
            'is_active' => true,
        ]);
        PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $part->id,
            'standard_name' => 'Standard ERA ACT-01',
            'input_type' => 'number',
            'target_value' => 1,
            'is_required' => false,
            'is_active' => true,
        ]);

        $service = app(PmExecutionService::class);
        $selected = $service->findScheduleDateForExecutor($this->machine);
        $this->assertSame($activeDate->id, $selected->id);

        $execution = $service->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );
        $this->assertSame($activeDate->id, $execution->pm_schedule_date_id);
    }

    /**
     * Schedule PAUSED tidak menyediakan kandidat start baru sama sekali;
     * resolver dan writer keduanya gagal tanpa membuat execution.
     */
    public function test_paused_schedule_cannot_start_new_execution(): void
    {
        PmSchedule::query()->whereKey($this->scheduleDate->pm_schedule_id)->update([
            'lifecycle_status' => 'paused',
            'is_active' => false,
        ]);

        $service = app(PmExecutionService::class);

        try {
            $service->findScheduleDateForExecutor($this->machine);
            $this->fail('Paused schedule must not be a new-start candidate.');
        } catch (ValidationException $exception) {
            $this->assertSame('Tidak ada jadwal PM aktif untuk mesin ini.', $exception->validator->errors()->first());
        }

        try {
            $service->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('startExecutionOnly must reject paused-era new start.');
        } catch (ValidationException) {
            $this->assertSame(0, PmExecution::query()->count());
        }
    }

    /**
     * Mesin nonaktif tidak menyediakan kandidat start baru, walau Schedule live.
     */
    public function test_inactive_machine_cannot_start_new_execution(): void
    {
        $this->machine->forceFill(['lifecycle_status' => 'inactive', 'is_active' => false])->save();

        $service = app(PmExecutionService::class);

        try {
            $service->findScheduleDateForExecutor($this->machine);
            $this->fail('Non-live machine must not be a new-start candidate.');
        } catch (ValidationException $exception) {
            $this->assertSame('Tidak ada jadwal PM aktif untuk mesin ini.', $exception->validator->errors()->first());
        }

        try {
            $service->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('startExecutionOnly must reject non-live machine new start.');
        } catch (ValidationException) {
            $this->assertSame(0, PmExecution::query()->count());
        }
    }

    /**
     * Recommission era baru: resolusi harus mengembalikan occurrence era baru
     * dan tidak menyentuh occurrence era ENDED.
     */
    public function test_recommissioned_machine_resolves_new_era_occurrence_only(): void
    {
        $this->endFixtureScheduleEra();
        $oldOccurrence = $this->scheduleDate;
        $assignmentId = PmSchedule::query()->findOrFail($oldOccurrence->pm_schedule_id)->pm_checksheet_machine_id;

        $newDate = $this->createEraOccurrence($this->machine, 'RCM-01', $assignmentId, [], now()->toDateString());

        $execution = app(PmExecutionService::class)->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );

        $this->assertSame($newDate->id, $execution->pm_schedule_date_id);
        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $oldOccurrence->id)->count());

        // Occurrence era ENDED tetap tersimpan dengan atribut tidak berubah.
        $this->assertTrue(
            PmScheduleDate::query()
                ->whereKey($oldOccurrence->id)
                ->where('status', 'scheduled')
                ->whereDate('scheduled_date', $oldOccurrence->scheduled_date->toDateString())
                ->exists()
        );
    }

    /**
     * Era ENDED tanpa era baru: occurrence scheduled tidak boleh jadi start baru.
     */
    public function test_ended_era_occurrence_without_execution_is_not_startable(): void
    {
        $this->endFixtureScheduleEra();

        $service = app(PmExecutionService::class);

        try {
            $service->findScheduleDateForExecutor($this->machine);
            $this->fail('ENDED-era occurrence must not be a new-start candidate.');
        } catch (ValidationException $exception) {
            $this->assertSame('Tidak ada jadwal PM aktif untuk mesin ini.', $exception->validator->errors()->first());
        }

        try {
            $service->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('startExecutionOnly must reject ENDED-era new start.');
        } catch (ValidationException) {
            $this->assertSame(0, PmExecution::query()->count());
        }
    }

    public function test_operator_can_open_pm_executor_page(): void
    {
        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertOk();
        $response->assertSee('PM Executor');
        $response->assertSee('Kondisi Gearbox');
        $response->assertSee('Mulai PM');
        $response->assertDontSee('Simpan Draft');
        $response->assertDontSee('Submit PM');
    }

    public function test_executor_page_without_execution_renders_start_only_controls(): void
    {
        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertOk();
        $response->assertSee('Mulai PM');
        $response->assertSee('/pm/executor/'.$this->machine->machine_code.'/start', false);
        $response->assertSee('<input type="hidden" name="_token"', false);
        $response->assertDontSee('<form id="pm-executor-form"', false);
        $response->assertDontSee('Simpan Draft');
        $response->assertDontSee('Submit PM');

        // Kontrol hasil dirender disabled selama execution canonical belum ada.
        $body = (string) $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*name="execution_number\['.$this->numberStandard->id.'\]")(?=[^>]*disabled)[^>]*>/',
            $body,
        );
        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*data-action-option="'.$this->actionStandard->id.'")(?=[^>]*disabled)[^>]*>/',
            $body,
        );
        $this->assertMatchesRegularExpression(
            '/<textarea(?=[^>]*name="part_notes\['.$this->part->id.'\]")(?=[^>]*disabled)[^>]*>/',
            $body,
        );
        $this->assertDatabaseCount('pm_executions', 0);
    }

    public function test_admin_and_guest_cannot_access_pm_executor_page(): void
    {
        $this->actingAs($this->admin)
            ->get("/pm/executor/{$this->machine->machine_code}")
            ->assertRedirect('/403');

        $guestSession = GuestSession::query()->create([
            'guest_name' => 'Guest Prime',
            'session_id' => 'guest-session',
            'login_at' => now(),
        ]);

        $this->withSession([
            'guest_session_id' => $guestSession->id,
            'guest_name' => $guestSession->guest_name,
        ])->get("/pm/executor/{$this->machine->machine_code}")
            ->assertRedirect('/403');
    }

    public function test_operator_cannot_open_executor_if_no_active_schedule(): void
    {
        $this->scheduleDate->forceFill([
            'status' => 'approved',
        ])->save();

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertRedirect("/machines/{$this->machine->machine_code}");
    }

    public function test_executor_selects_earliest_preserved_occurrence_and_keeps_in_progress_precedence(): void
    {
        $location = Location::query()->create([
            'location_code' => 'LOC-UC-018',
            'location_name' => 'Upcast 18',
            'is_active' => true,
        ]);

        $machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'UC-018',
            'machine_name' => 'REPAIR LINE 18',
            'qr_token' => 'qr-uc-018',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);

        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-UC-018',
            'checksheet_name' => 'Checksheet UC-018',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $machine->id,
            'created_by' => $this->admin->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [5],
            'operational_from' => '2026-08-01',
            'start_date' => '2026-08-01',
            'generate_until' => '2026-09-01',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        foreach (['2026-06-06', '2026-06-13'] as $date) {
            PmScheduleDate::query()->create([
                'pm_schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'scheduled_date' => $date,
                'status' => 'scheduled',
            ]);
        }

        foreach (['2026-08-07', '2026-08-14', '2026-08-21', '2026-08-28'] as $date) {
            PmScheduleDate::query()->create([
                'pm_schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'scheduled_date' => $date,
                'status' => 'scheduled',
            ]);
        }

        $reconciler = app(PmScheduleDateReconciler::class);
        $result = $reconciler->reconcile($schedule);

        // Slice A: baris stale sebelum batas materialisasi dipertahankan, bukan dihapus.
        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => '2026-06-06 00:00:00',
        ]);

        $service = app(PmExecutionService::class);
        $selected = $service->findScheduleDateForExecutor($machine);

        // Occurrence scheduled paling awal yang dipertahankan tetap dipilih executor.
        $this->assertSame('2026-06-06', $selected->scheduled_date->toDateString());

        $historicalInProgress = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => '2026-06-20',
            'status' => 'in_progress',
        ]);

        $selected = $service->findScheduleDateForExecutor($machine);

        $this->assertSame($historicalInProgress->id, $selected->id);
        $this->assertSame('2026-06-20', $selected->scheduled_date->toDateString());
    }

    public function test_overdue_occurrence_can_start_a_new_execution(): void
    {
        $this->scheduleDate->update(['scheduled_date' => now()->subDay()->toDateString()]);

        $response = $this->from("/pm/executor/{$this->machine->machine_code}")
            ->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start", [
                'expected_revision' => 0,
                'execution_action' => [
                    $this->actionStandard->id => 'OK',
                ],
                'execution_number' => [
                    $this->numberStandard->id => 60,
                    $this->rangeStandard->id => 120,
                ],
            ]);

        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $this->assertDatabaseHas('pm_executions', [
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_future_occurrence_is_rejected_by_executor_context(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 17:38:34', 'UTC'));

        try {
            $this->scheduleDate->update([
                'scheduled_date' => BusinessDate::today()->addDay()->toDateString(),
            ]);

            try {
                app(PmExecutionService::class)->getExecutorContext($this->machine);
                $this->fail('Future occurrence must not expose a new executor context.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    'PM terjadwal belum dapat dimulai sebelum tanggal jadwalnya.',
                    $exception->validator->errors()->first('machine'),
                );
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_future_occurrence_start_redirects_with_validation_error_without_side_effects(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 17:38:34', 'UTC'));

        try {
            $this->scheduleDate->update([
                'scheduled_date' => BusinessDate::today()->addDay()->toDateString(),
            ]);

            $response = $this->from("/pm/executor/{$this->machine->machine_code}")
                ->actingAs($this->operator)
                ->post("/pm/executor/{$this->machine->machine_code}/start", [
                    'expected_revision' => 0,
                ]);

            $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");
            $response->assertSessionHasErrors([
                'machine' => 'PM terjadwal belum dapat dimulai sebelum tanggal jadwalnya.',
            ]);
            $this->assertDatabaseCount('pm_executions', 0);
            $this->assertDatabaseHas('pm_schedule_dates', [
                'id' => $this->scheduleDate->id,
                'status' => 'scheduled',
            ]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_future_occurrence_with_in_progress_execution_is_reused(): void
    {
        $this->scheduleDate->update(['scheduled_date' => now()->addDay()->toDateString()]);
        $existing = $this->createExecutionWithMedia('in_progress');

        $resolved = app(PmExecutionService::class)->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame(1, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * BR-PM-EXEC-004: aksi hasil Operator tanpa execution canonical ditolak
     * fail-closed tanpa membuat execution, item, history, atau jejak apa pun.
     */
    public function test_draft_without_execution_is_rejected_without_creating_execution(): void
    {
        $response = $this->from("/pm/executor/{$this->machine->machine_code}")
            ->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => 0,
                'execution_action' => [$this->actionStandard->id => 'OK'],
                'execution_number' => [$this->numberStandard->id => 60, $this->rangeStandard->id => 120],
            ]);

        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $response->assertSessionHasErrors([
            'machine' => 'PM belum dimulai. Mulai PM terlebih dahulu.',
        ]);

        $this->assertDatabaseCount('pm_executions', 0);
        $this->assertDatabaseCount('pm_execution_items', 0);
        $this->assertDatabaseCount('pm_execution_history', 0);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'scheduled',
        ]);
        $this->assertSame(0, \DB::table('user_activity_logs')->where('module_name', 'pm_executor')->count());
    }

    public function test_submit_without_execution_is_rejected_without_notification_or_next_occurrence(): void
    {
        $response = $this->from("/pm/executor/{$this->machine->machine_code}")
            ->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/submit", [
                'expected_revision' => 0,
                'execution_action' => [$this->actionStandard->id => 'OK'],
                'execution_number' => [$this->numberStandard->id => 60, $this->rangeStandard->id => 120],
            ]);

        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $response->assertSessionHasErrors([
            'machine' => 'PM belum dimulai. Mulai PM terlebih dahulu.',
        ]);

        $this->assertDatabaseCount('pm_executions', 0);
        $this->assertSame(0, \DB::table('notifications')->where('notification_type', 'pm_waiting_review')->count());
        $this->assertDatabaseMissing('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'waiting_review',
        ]);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_media_upload_without_execution_is_rejected_without_persisting_file(): void
    {
        $filesBefore = Storage::disk('public')->files('pm-execution-media');

        $this->actingAs($this->operator)
            ->postJson("/pm/executor/{$this->machine->machine_code}/media/upload", [
                'part_id' => $this->part->id,
                'part_note' => 'Foto tanpa execution',
                'media_file' => UploadedFile::fake()->image('unstarted.jpg', 640, 480),
            ])->assertStatus(422)
            ->assertJsonPath('errors.machine.0', 'PM belum dimulai. Mulai PM terlebih dahulu.');

        $this->assertDatabaseCount('pm_executions', 0);
        $this->assertDatabaseCount('pm_execution_media', 0);
        $this->assertSame($filesBefore, Storage::disk('public')->files('pm-execution-media'));
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_start_pm_creates_in_progress_execution_without_result_side_effects(): void
    {
        // Start membekukan definisi checksheet tanpa menulis hasil operator.
        $response = $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/start");

        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $execution = PmExecution::query()->firstOrFail();
        $this->assertSame('in_progress', $execution->status);
        $this->assertNotNull($execution->started_at);
        $this->assertNotNull($execution->definition_snapshot_at);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseCount('pm_execution_items', 3);
        $this->assertDatabaseHas('pm_execution_items', [
            'pm_execution_id' => $execution->id,
            'pm_checksheet_standard_id' => $this->actionStandard->id,
            'standard_name_snapshot' => 'Kondisi Gearbox',
            'selection_mode_snapshot' => 'single',
            'is_required_snapshot' => 1,
        ]);
        $this->assertDatabaseHas('user_activity_logs', [
            'module_name' => 'pm_executor',
            'action' => 'start_pm',
            'table_name' => 'pm_executions',
            'record_id' => $execution->id,
        ]);

        // Start kedua idempoten: tetap satu execution canonical, started_at tidak berubah.
        $firstStartedAt = $execution->started_at;
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $this->assertSame(1, PmExecution::query()->count());
        $this->assertTrue($execution->fresh()->started_at->equalTo($firstStartedAt));
        $this->assertDatabaseCount('pm_execution_items', 3);
    }

    public function test_start_pm_with_empty_active_definition_is_rejected_without_side_effects(): void
    {
        $this->actionStandard->forceFill(['is_active' => false])->save();
        $this->numberStandard->forceFill(['is_active' => false])->save();
        $this->rangeStandard->forceFill(['is_active' => false])->save();

        $response = $this->from("/pm/executor/{$this->machine->machine_code}")
            ->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start");

        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $response->assertSessionHasErrors([
            'machine' => 'Part atau standard checksheet belum tersedia.',
        ]);
        $this->assertDatabaseCount('pm_executions', 0);
        $this->assertDatabaseCount('pm_execution_items', 0);
        $this->assertDatabaseCount('pm_execution_history', 0);
        $this->assertSame(0, \DB::table('user_activity_logs')->where('module_name', 'pm_executor')->count());
        $this->assertSame(0, \DB::table('notifications')->where('notification_type', 'pm_waiting_review')->count());
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_start_pm_excludes_inactive_mapped_master_actions_from_snapshot(): void
    {
        $ok = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $changeOil = PmAction::query()->create(['action_label' => 'GANTI OLI', 'is_active' => true]);
        $addOil = PmAction::query()->create(['action_label' => 'TAMBAH OLI', 'is_active' => false]);
        $this->actionStandard->actions()->sync([
            $ok->id => ['position' => 1],
            $changeOil->id => ['position' => 2],
            $addOil->id => ['position' => 3],
        ]);

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $item = PmExecutionItem::query()
            ->where('pm_checksheet_standard_id', $this->actionStandard->id)
            ->firstOrFail();

        $this->assertSame(['OK', 'GANTI OLI'], $item->action_options_snapshot);
        $this->assertSame(
            ['OK', 'GANTI OLI', 'TAMBAH OLI'],
            $this->actionStandard->fresh()->actions()->pluck('action_label')->all(),
        );
    }

    public function test_deactivated_master_action_remains_usable_in_existing_frozen_snapshot(): void
    {
        $ok = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $changeOil = PmAction::query()->create(['action_label' => 'GANTI OLI', 'is_active' => true]);
        $this->actionStandard->actions()->sync([
            $ok->id => ['position' => 1],
            $changeOil->id => ['position' => 2],
        ]);

        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);
        $changeOil->forceFill(['is_active' => false])->save();

        $this->actingAs($this->operator)
            ->get("/pm/executor/{$this->machine->machine_code}")
            ->assertSee('data-option-value="GANTI OLI"', false);
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$itemId => 'GANTI OLI'],
                'execution_number' => [
                    $this->itemIdFor($this->numberStandard) => 60,
                    $this->itemIdFor($this->rangeStandard) => 120,
                ],
            ])
            ->assertSessionHasNoErrors();

        $item = PmExecutionItem::query()->findOrFail($itemId);
        $this->assertSame(['OK', 'GANTI OLI'], $item->action_options_snapshot);
        $this->assertSame('GANTI OLI', $item->action_value);
    }

    public function test_start_pm_with_only_inactive_mapped_master_actions_is_rejected_without_side_effects(): void
    {
        $inactiveAction = PmAction::query()->create(['action_label' => 'TAMBAH OLI', 'is_active' => false]);
        $this->actionStandard->actions()->sync([$inactiveAction->id => ['position' => 1]]);

        $response = $this->from("/pm/executor/{$this->machine->machine_code}")
            ->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start");

        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $response->assertSessionHasErrors([
            'machine' => 'Standard action tidak memiliki opsi aktif.',
        ]);
        $this->assertDatabaseCount('pm_executions', 0);
        $this->assertDatabaseCount('pm_execution_items', 0);
        $this->assertDatabaseCount('pm_execution_history', 0);
        $this->assertSame(0, DB::table('user_activity_logs')->where('module_name', 'pm_executor')->count());
        $this->assertSame(0, DB::table('notifications')->where('notification_type', 'pm_waiting_review')->count());
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_submit_pm_with_warning_is_allowed_and_creates_notification(): void
    {
        // BR-PM-EXEC-004: submit hanya menuliskan hasil pada execution yang sudah dimulai.
        $this->startCanonicalExecution();

        $response = $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => 0,
            'execution_action' => [
                $this->itemIdFor($this->actionStandard) => 'OK',
            ],
            'execution_number' => [
                $this->itemIdFor($this->numberStandard) => 55,
                $this->itemIdFor($this->rangeStandard) => 220,
            ],
        ]);

        $response->assertRedirect("/machines/{$this->machine->machine_code}");

        $execution = PmExecution::query()->firstOrFail();
        $this->assertSame('waiting_review', $execution->status);
        $this->assertNotNull($execution->submitted_at);

        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'waiting_review',
        ]);

        $this->assertDatabaseHas('pm_execution_items', [
            'pm_execution_id' => $execution->id,
            'pm_checksheet_standard_id' => $this->numberStandard->id,
            'is_warning' => 1,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_waiting_review',
            'related_table' => 'pm_executions',
            'related_id' => $execution->id,
        ]);

        $this->assertTrue(
            PmScheduleDate::query()
                ->where('pm_schedule_id', $this->scheduleDate->pm_schedule_id)
                ->where('machine_id', $this->machine->id)
                ->whereDate('scheduled_date', now()->addDay()->toDateString())
                ->where('status', 'scheduled')
                ->exists(),
        );
    }

    public function test_submit_pm_does_not_duplicate_existing_next_schedule_date(): void
    {
        $this->startCanonicalExecution();

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->scheduleDate->pm_schedule_id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => 0,
            'execution_action' => [
                $this->itemIdFor($this->actionStandard) => 'OK',
            ],
            'execution_number' => [
                $this->itemIdFor($this->numberStandard) => 60,
                $this->itemIdFor($this->rangeStandard) => 120,
            ],
        ])->assertRedirect("/machines/{$this->machine->machine_code}");

        $this->assertSame(
            1,
            PmScheduleDate::query()
                ->where('pm_schedule_id', $this->scheduleDate->pm_schedule_id)
                ->where('machine_id', $this->machine->id)
                ->whereDate('scheduled_date', now()->addDay()->toDateString())
                ->count(),
        );
    }

    public function test_machine_profile_next_pm_updates_after_submit_when_next_schedule_was_missing(): void
    {
        $this->startCanonicalExecution();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => 0,
            'execution_action' => [
                $this->itemIdFor($this->actionStandard) => 'OK',
            ],
            'execution_number' => [
                $this->itemIdFor($this->numberStandard) => 60,
                $this->itemIdFor($this->rangeStandard) => 120,
            ],
        ])->assertRedirect("/machines/{$this->machine->machine_code}");

        $this->actingAs($this->operator)
            ->get("/machines/{$this->machine->machine_code}")
            ->assertOk()
            ->assertSee(now()->addDay()->translatedFormat('d M Y'))
            ->assertSee('Scheduled');
    }

    public function test_operator_can_upload_and_delete_pm_media(): void
    {
        $this->startCanonicalExecution();

        $uploadResponse = $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/media/upload", [
            'part_id' => $this->part->id,
            'part_note' => 'Foto kondisi aktual',
            'media_file' => UploadedFile::fake()->image('gearbox.jpg', 800, 600),
        ]);

        $uploadResponse->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $media = PmExecutionMedia::query()->firstOrFail();
        Storage::disk('public')->assertExists($media->file_path);

        $deleteResponse = $this->actingAs($this->operator)->delete("/pm/executor/{$this->machine->machine_code}/media/{$media->id}");
        $deleteResponse->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $this->assertDatabaseMissing('pm_execution_media', [
            'id' => $media->id,
        ]);
    }

    /**
     * RED-1 (B3): O < T. Completion historis 2026-09-05 dan tidak ada occurrence
     * berikutnya; submit harus membuat occurrence live baru >= T (2026-09-10),
     * bukan menuliskan 2026-09-06..09-09.
     */
    public function test_submit_after_historical_completion_materializes_next_occurrence_at_or_after_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 00:00:00', 'Asia/Jakarta'));

        // Era fixture diakhiri agar era baru di bawah menjadi satu-satunya era current.
        $this->endFixtureScheduleEra();

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => PmChecksheetMachine::query()->firstOrFail()->id,
            'frequency_type' => 'daily',
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-01',
            'generate_until' => '2026-10-01',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Fixture default tidak boleh mengalahkan occurrence historis yang diuji.
        $this->scheduleDate->forceFill(['status' => 'approved'])->save();

        // Occurrence historis yang sudah completed: 2026-09-05 (sebelum T).
        $historical = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-09-05',
            'status' => 'scheduled',
        ]);

        // Jalur eksekusi nyata yang memicu pembuatan next-date: submit.
        // Mulai PM lebih dahulu; resolver memilih occurrence historis 2026-09-05.
        $this->startCanonicalExecution();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => 0,
            'execution_action' => [
                $this->itemIdFor($this->actionStandard) => 'OK',
            ],
            'execution_number' => [
                $this->itemIdFor($this->numberStandard) => 60,
                $this->itemIdFor($this->rangeStandard) => 120,
            ],
        ])->assertRedirect("/machines/{$this->machine->machine_code}");

        // Tidak ada occurrence live baru antara selesai historis dan hari ini.
        $backfillCount = PmScheduleDate::query()
            ->where('pm_schedule_id', $schedule->id)
            ->where('machine_id', $this->machine->id)
            ->whereBetween('scheduled_date', ['2026-09-06 00:00:00', '2026-09-09 23:59:59'])
            ->count();
        $this->assertSame(0, $backfillCount);

        // Occurrence live pertama setelah submit harus >= hari bisnis (2026-09-10).
        $next = PmScheduleDate::query()
            ->where('pm_schedule_id', $schedule->id)
            ->where('machine_id', $this->machine->id)
            ->where('id', '!=', $historical->id)
            ->orderBy('scheduled_date')
            ->first();

        $this->assertNotNull($next);
        $this->assertGreaterThanOrEqual(
            '2026-09-10',
            $next->scheduled_date->toDateString(),
            'Writer eksekusi membuat occurrence live sebelum batas materialisasi.',
        );
        $this->assertSame('scheduled', $next->status);
    }

    /**
     * RED-2 (B1): operational_from = NULL adalah legacy unresolved; writer
     * eksekusi tidak boleh otomatis mematerialisasi occurrence baru.
     */
    public function test_submit_with_legacy_null_operational_from_does_not_materialize_next_occurrence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 00:00:00', 'Asia/Jakarta'));

        // Era fixture diakhiri agar era legacy di bawah menjadi satu-satunya era current.
        $this->endFixtureScheduleEra();

        $legacySchedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => PmChecksheetMachine::query()->firstOrFail()->id,
            'frequency_type' => 'daily',
            'operational_from' => null,
            'start_date' => '2026-09-01',
            'generate_until' => '2026-10-01',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->scheduleDate->forceFill(['status' => 'approved'])->save();
        $legacyOccurrence = PmScheduleDate::query()->create([
            'pm_schedule_id' => $legacySchedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-09-10',
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/start", [
            'expected_revision' => 0,
            'execution_action' => [$this->actionStandard->id => 'OK'],
            'execution_number' => [$this->numberStandard->id => 60, $this->rangeStandard->id => 120],
        ])->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => 0,
            'execution_action' => [$this->itemIdFor($this->actionStandard) => 'OK'],
            'execution_number' => [$this->itemIdFor($this->numberStandard) => 60, $this->itemIdFor($this->rangeStandard) => 120],
        ])->assertRedirect("/machines/{$this->machine->machine_code}");

        // Riwayat submit harus tetap tersimpan pada occurrence legacy yang sama.
        $execution = PmExecution::query()
            ->where('pm_schedule_date_id', $legacyOccurrence->id)
            ->first();
        $this->assertNotNull($execution);
        $this->assertSame($legacyOccurrence->id, $execution->pm_schedule_date_id);
        $this->assertSame('waiting_review', $execution->fresh()->status);
        $this->assertSame('waiting_review', $legacyOccurrence->fresh()->status);

        // Legacy NULL tetap fail-closed: tidak membuat occurrence berikutnya.
        $this->assertSame(
            1,
            PmScheduleDate::query()
                ->where('pm_schedule_id', $legacySchedule->id)
                ->where('machine_id', $this->machine->id)
                ->count(),
        );
        $this->assertSame(
            0,
            PmScheduleDate::query()
                ->where('pm_schedule_id', $legacySchedule->id)
                ->where('machine_id', $this->machine->id)
                ->where('id', '!=', $legacyOccurrence->id)
                ->count(),
        );
    }

    public function test_submit_respects_explicit_later_start_date_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 00:00:00', 'Asia/Jakarta'));

        // Era fixture diakhiri agar era baru di bawah menjadi satu-satunya era current.
        $this->endFixtureScheduleEra();

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => PmChecksheetMachine::query()->firstOrFail()->id,
            'frequency_type' => 'daily',
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-20',
            'generate_until' => '2026-10-01',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->scheduleDate->forceFill(['status' => 'approved'])->save();
        $historical = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-09-05',
            'status' => 'scheduled',
        ]);

        $this->startCanonicalExecution();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => 0,
            'execution_action' => [$this->itemIdFor($this->actionStandard) => 'OK'],
            'execution_number' => [$this->itemIdFor($this->numberStandard) => 60, $this->itemIdFor($this->rangeStandard) => 120],
        ])->assertRedirect("/machines/{$this->machine->machine_code}");

        $firstNext = PmScheduleDate::query()->where('pm_schedule_id', $schedule->id)
            ->where('machine_id', $this->machine->id)->where('id', '!=', $historical->id)
            ->orderBy('scheduled_date')->first();
        $this->assertNotNull($firstNext);
        $this->assertGreaterThanOrEqual('2026-09-20', $firstNext->scheduled_date->toDateString());
    }

    /**
     * BR-PM-LC-002: occurrence berikutnya setelah submit tidak boleh mendahului
     * boundary effective-live terbaru dari parent (Machine maupun Schedule).
     */
    public function test_submit_next_occurrence_respects_latest_parent_effective_live_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 00:00:00', 'Asia/Jakarta'));

        // Era fixture diakhiri agar era baru di bawah menjadi satu-satunya era current.
        $this->endFixtureScheduleEra();

        // Dua boundary berbeda: Machine lebih awal, Schedule lebih baru. Yang
        // berlaku adalah yang terbaru, sehingga pembuat occurrence tidak boleh
        // menghasilkan tanggal di antara keduanya.
        $this->machine->forceFill(['effective_live_from' => '2026-09-15'])->save();

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => PmChecksheetMachine::query()->firstOrFail()->id,
            'frequency_type' => 'daily',
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-01',
            'generate_until' => '2026-10-01',
            'is_active' => true,
            'effective_live_from' => '2026-09-20',
            'created_by' => $this->admin->id,
        ]);
        $this->scheduleDate->forceFill(['status' => 'approved'])->save();

        // Occurrence historis yang diselesaikan submit; resolver memilih ini.
        $historical = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-09-05',
            'status' => 'scheduled',
        ]);

        $this->startCanonicalExecution();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => 0,
            'execution_action' => [$this->itemIdFor($this->actionStandard) => 'OK'],
            'execution_number' => [$this->itemIdFor($this->numberStandard) => 60, $this->itemIdFor($this->rangeStandard) => 120],
        ])->assertRedirect("/machines/{$this->machine->machine_code}");

        $this->assertSame(
            ['2026-09-05', '2026-09-20'],
            PmScheduleDate::query()
                ->where('pm_schedule_id', $schedule->id)
                ->where('machine_id', $this->machine->id)
                ->orderBy('scheduled_date')
                ->get()
                ->map(fn (PmScheduleDate $date): string => $date->scheduled_date->toDateString())
                ->all(),
            'Occurrence berikutnya harus tepat pada boundary effective-live terbaru.',
        );
        $this->assertSame(0, PmScheduleDate::query()
            ->where('pm_schedule_id', $schedule->id)
            ->where('id', '!=', $historical->id)
            ->where('scheduled_date', '<', '2026-09-20 00:00:00')
            ->count(), 'Tidak boleh ada occurrence sebelum boundary terbaru.');
    }

    /**
     * Memastikan operator dapat menghapus media beserta dua file saat PM aktif.
     */
    public function test_in_progress_media_delete_removes_all_files_and_preserves_active_lifecycle(): void
    {
        $execution = $this->createExecutionWithMedia('in_progress', [
            'started_at' => now()->subMinutes(10),
        ]);
        $media = $execution->media()->firstOrFail();
        $executionHistoryCount = $execution->history()->count();

        app(PmExecutionMediaService::class)->deleteMedia($media);

        Storage::disk('public')->assertMissing($media->file_path);
        Storage::disk('public')->assertMissing($media->original_file_path);
        $this->assertDatabaseMissing('pm_execution_media', ['id' => $media->id]);
        $this->assertDatabaseHas('pm_executions', [
            'id' => $execution->id,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $this->scheduleDate->id,
            'status' => 'in_progress',
        ]);
        $this->assertSame($executionHistoryCount, $execution->history()->count());
    }

    /**
     * Memastikan media pada PM yang menunggu review tidak dapat dimutasi.
     */
    public function test_waiting_review_media_delete_preserves_files_and_execution_state(): void
    {
        $statusChangedAt = now()->subMinutes(5)->seconds(0);
        $execution = $this->createExecutionWithMedia('waiting_review', [
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ]);
        $this->scheduleDate->forceFill([
            'status' => 'waiting_review',
            'status_changed_at' => $statusChangedAt,
        ])->save();
        $execution->forceFill(['submitted_at' => now()->subMinutes(30)])->save();
        $media = $execution->media()->firstOrFail();
        $before = $execution->fresh();
        $historyCount = $execution->history()->count();

        try {
            app(PmExecutionMediaService::class)->deleteMedia($media);
            $this->fail('Media deletion should be rejected for waiting_review.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Media PM hanya dapat dihapus saat PM sedang dikerjakan.',
                collect($exception->errors())->flatten()->first(),
            );
        }
        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        $this->assertSame($before->status, $execution->fresh()->status);
        $this->assertSame($before->submitted_at?->toDateTimeString(), $execution->fresh()->submitted_at?->toDateTimeString());
        $this->assertSame($statusChangedAt->toDateTimeString(), $this->scheduleDate->fresh()->status_changed_at?->toDateTimeString());
        $this->assertSame('waiting_review', $this->scheduleDate->fresh()->status);
        $this->assertSame($historyCount, $execution->history()->count());
    }

    /**
     * Memastikan media pada PM approved tetap menjadi histori yang terlindungi.
     */
    public function test_approved_media_delete_preserves_approval_and_history(): void
    {
        $approvedAt = now()->subMinutes(20)->seconds(0);
        $execution = $this->createExecutionWithMedia('approved', [
            'started_at' => now()->subHours(2),
            'submitted_at' => now()->subHour(),
            'approved_at' => $approvedAt,
            'approved_by' => $this->admin->id,
            'approved_by_name_snapshot' => $this->admin->name,
            'review_note' => 'Disetujui setelah pemeriksaan.',
        ]);
        $this->scheduleDate->forceFill([
            'status' => 'approved',
            'status_changed_at' => $approvedAt,
        ])->save();
        $media = $execution->media()->firstOrFail();
        $historyCount = $execution->history()->count();
        $before = $execution->fresh();

        try {
            app(PmExecutionMediaService::class)->deleteMedia($media);
            $this->fail('Media deletion should be rejected for approved.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Media PM hanya dapat dihapus saat PM sedang dikerjakan.',
                collect($exception->errors())->flatten()->first(),
            );
        }
        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        $after = $execution->fresh();
        $this->assertSame('approved', $after->status);
        $this->assertSame($before->approved_at?->toDateTimeString(), $after->approved_at?->toDateTimeString());
        $this->assertSame($before->approved_by, $after->approved_by);
        $this->assertSame($before->review_note, $after->review_note);
        $this->assertSame('approved', $this->scheduleDate->fresh()->status);
        $this->assertSame($historyCount, $execution->history()->count());
    }

    /**
     * Membuktikan jalur HTTP controller menolak DELETE media pada PM waiting_review
     * sekaligus memastikan seluruh bukti (file, media, execution, schedule, history) tetap utuh.
     */
    public function test_http_delete_media_waiting_review_is_rejected_via_route_and_preserves_evidence(): void
    {
        $statusChangedAt = now()->subMinutes(5)->seconds(0);
        $execution = $this->createExecutionWithMedia('waiting_review', [
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ]);
        $this->scheduleDate->forceFill([
            'status' => 'waiting_review',
            'status_changed_at' => $statusChangedAt,
        ])->save();
        $media = $execution->media()->firstOrFail();
        $historyCount = $execution->history()->count();
        $before = $execution->fresh();

        $response = $this->actingAs($this->operator)->deleteJson(
            "/pm/executor/{$this->machine->machine_code}/media/{$media->id}",
        );

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Media PM hanya dapat dihapus saat PM sedang dikerjakan.',
        ]);

        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        $after = $execution->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame(
            $before->submitted_at?->toDateTimeString(),
            $after->submitted_at?->toDateTimeString(),
        );
        $this->assertSame($statusChangedAt->toDateTimeString(), $this->scheduleDate->fresh()->status_changed_at?->toDateTimeString());
        $this->assertSame('waiting_review', $this->scheduleDate->fresh()->status);
        $this->assertSame($historyCount, $execution->history()->count());
    }

    /**
     * Membuktikan jalur HTTP controller menolak DELETE media pada PM approved
     * tanpa mengubah metadata approval maupun histori.
     */
    public function test_http_delete_media_approved_is_rejected_via_route_and_preserves_evidence(): void
    {
        $approvedAt = now()->subMinutes(20)->seconds(0);
        $execution = $this->createExecutionWithMedia('approved', [
            'started_at' => now()->subHours(2),
            'submitted_at' => now()->subHour(),
            'approved_at' => $approvedAt,
            'approved_by' => $this->admin->id,
            'approved_by_name_snapshot' => $this->admin->name,
            'review_note' => 'Disetujui setelah pemeriksaan.',
        ]);
        $this->scheduleDate->forceFill([
            'status' => 'approved',
            'status_changed_at' => $approvedAt,
        ])->save();
        $media = $execution->media()->firstOrFail();
        $historyCount = $execution->history()->count();
        $before = $execution->fresh();

        $response = $this->actingAs($this->operator)->deleteJson(
            "/pm/executor/{$this->machine->machine_code}/media/{$media->id}",
        );

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Media PM hanya dapat dihapus saat PM sedang dikerjakan.',
        ]);

        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        $after = $execution->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->approved_at?->toDateTimeString(), $after->approved_at?->toDateTimeString());
        $this->assertSame($before->approved_by, $after->approved_by);
        $this->assertSame($before->review_note, $after->review_note);
        $this->assertSame($approvedAt->toDateTimeString(), $this->scheduleDate->fresh()->status_changed_at?->toDateTimeString());
        $this->assertSame('approved', $this->scheduleDate->fresh()->status);
        $this->assertSame($historyCount, $execution->history()->count());
    }

    /**
     * Membuktikan jalur redirect+flash saat DELETE non-JSON ditolak pada PM waiting_review.
     */
    public function test_http_delete_media_waiting_review_redirect_branch_preserves_evidence(): void
    {
        $statusChangedAt = now()->subMinutes(5)->seconds(0);
        $execution = $this->createExecutionWithMedia('waiting_review', [
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ]);
        $this->scheduleDate->forceFill([
            'status' => 'waiting_review',
            'status_changed_at' => $statusChangedAt,
        ])->save();
        $media = $execution->media()->firstOrFail();
        $historyCount = $execution->history()->count();
        $before = $execution->fresh();

        // Permintaan tanpa Accept: application/json → jalur redirect + flash_error.
        $response = $this->actingAs($this->operator)
            ->delete("/pm/executor/{$this->machine->machine_code}/media/{$media->id}");

        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $response->assertSessionHas('flash_error', 'Media PM hanya dapat dihapus saat PM sedang dikerjakan.');

        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        $after = $execution->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame(
            $before->submitted_at?->toDateTimeString(),
            $after->submitted_at?->toDateTimeString(),
        );
        $this->assertSame(
            $statusChangedAt->toDateTimeString(),
            $this->scheduleDate->fresh()->status_changed_at?->toDateTimeString(),
        );
        $this->assertSame('waiting_review', $this->scheduleDate->fresh()->status);
        $this->assertSame($historyCount, $execution->history()->count());
    }

    /**
     * Membuktikan jalur redirect+flash saat DELETE non-JSON ditolak pada PM approved.
     */
    public function test_http_delete_media_approved_redirect_branch_preserves_evidence(): void
    {
        $approvedAt = now()->subMinutes(20)->seconds(0);
        $execution = $this->createExecutionWithMedia('approved', [
            'started_at' => now()->subHours(2),
            'submitted_at' => now()->subHour(),
            'approved_at' => $approvedAt,
            'approved_by' => $this->admin->id,
            'approved_by_name_snapshot' => $this->admin->name,
            'review_note' => 'Disetujui setelah pemeriksaan.',
        ]);
        $this->scheduleDate->forceFill([
            'status' => 'approved',
            'status_changed_at' => $approvedAt,
        ])->save();
        $media = $execution->media()->firstOrFail();
        $historyCount = $execution->history()->count();
        $before = $execution->fresh();

        // Permintaan tanpa Accept: application/json → jalur redirect + flash_error.
        $response = $this->actingAs($this->operator)
            ->delete("/pm/executor/{$this->machine->machine_code}/media/{$media->id}");

        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $response->assertSessionHas('flash_error', 'Media PM hanya dapat dihapus saat PM sedang dikerjakan.');

        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        $after = $execution->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->approved_at?->toDateTimeString(), $after->approved_at?->toDateTimeString());
        $this->assertSame($before->approved_by, $after->approved_by);
        $this->assertSame($before->review_note, $after->review_note);
        $this->assertSame($approvedAt->toDateTimeString(), $this->scheduleDate->fresh()->status_changed_at?->toDateTimeString());
        $this->assertSame('approved', $this->scheduleDate->fresh()->status);
        $this->assertSame($historyCount, $execution->history()->count());
    }

    /**
     * Memastikan authentication, role, dan relasi machine/media tetap dijaga.
     */
    public function test_media_delete_rejects_unauthenticated_wrong_role_and_wrong_machine_without_mutation(): void
    {
        $execution = $this->createExecutionWithMedia('in_progress');
        $media = $execution->media()->firstOrFail();
        $otherMachine = Machine::query()->create([
            'location_id' => $this->machine->location_id,
            'machine_code' => 'UC-002',
            'machine_name' => 'MOTOR SERVO LAIN',
            'qr_token' => 'qr-uc-002',
            'is_active' => true,
        ]);
        $historyCount = $execution->history()->count();

        $this->delete("/pm/executor/{$this->machine->machine_code}/media/{$media->id}")
            ->assertRedirect('/login');
        $this->actingAs($this->admin)
            ->delete("/pm/executor/{$this->machine->machine_code}/media/{$media->id}")
            ->assertRedirect('/403');
        $this->actingAs($this->operator)
            ->delete("/pm/executor/{$otherMachine->machine_code}/media/{$media->id}")
            ->assertNotFound();

        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        $this->assertDatabaseHas('pm_executions', ['id' => $execution->id, 'status' => 'in_progress']);
        $this->assertDatabaseHas('pm_schedule_dates', ['id' => $this->scheduleDate->id, 'status' => 'in_progress']);
        $this->assertSame($historyCount, $execution->history()->count());
    }

    /**
     * Memastikan service membaca lifecycle yang tersimpan, bukan model stale.
     */
    public function test_media_delete_rechecks_persisted_lifecycle_after_execution_becomes_waiting_review(): void
    {
        $staleExecution = $this->createExecutionWithMedia('in_progress');
        $media = $staleExecution->media()->firstOrFail();
        PmExecution::query()->whereKey($staleExecution->id)->update(['status' => 'waiting_review']);
        PmScheduleDate::query()->whereKey($this->scheduleDate->id)->update(['status' => 'waiting_review']);
        $historyCount = PmExecution::query()->findOrFail($staleExecution->id)->history()->count();

        try {
            app(PmExecutionMediaService::class)->deleteMedia($media);
            $this->fail('Media deletion should be rejected for the persisted waiting_review state.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Media PM hanya dapat dihapus saat PM sedang dikerjakan.',
                collect($exception->errors())->flatten()->first(),
            );
        }

        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        $this->assertDatabaseHas('pm_executions', ['id' => $staleExecution->id, 'status' => 'waiting_review']);
        $this->assertDatabaseHas('pm_schedule_dates', ['id' => $this->scheduleDate->id, 'status' => 'waiting_review']);
        $this->assertSame($historyCount, PmExecution::query()->findOrFail($staleExecution->id)->history()->count());
    }

    /**
     * Test A — submit dengan lifecycle tersimpan yang sudah keluar dari in_progress
     * ditolak oleh canonical resolver sebelum mutasi item/media apapun.
     *
     * ADR-004: caller snapshot/context tidak menjadi sumber keputusan; resolver
     * selalu membaca ulang occurrence dan execution dari database dengan lock,
     * lalu menolak waiting_review sebagai occurrence yang sudah diproses.
     */
    public function test_stale_submit_is_rejected_before_any_draft_mutation(): void
    {
        // Execution in_progress dibuat sebagai kondisi awal; setelah ini status
        // database diubah ke waiting_review sehingga request berikutnya harus
        // melihat kondisi persisted, bukan kondisi in-memory yang lama.
        $execution = $this->createExecutionWithMedia('in_progress', ['started_at' => now()->subMinutes(5)]);

        // Ubah status di database setelah kondisi awal dibuat; request stale
        // tidak boleh memakai snapshot in_progress yang sudah tidak valid.
        PmExecution::query()->whereKey($execution->id)->update(['status' => 'waiting_review']);

        // Snapshot jumlah item/media sebelum request untuk membuktikan zero mutation.
        $staleItemCount = \DB::table('pm_execution_items')->where('pm_execution_id', $execution->id)->count();
        $staleMediaCount = $execution->media()->count();

        try {
            app(PmExecutionService::class)->saveDraft(
                request: Request::create('/'),
                machine: $this->machine,
                operator: $this->operator,
                actionValues: [$this->actionStandard->id => 'OK'],
                numberValues: [$this->numberStandard->id => '60', $this->rangeStandard->id => '120'],
                partNotes: [],
                expectedRevision: (int) $execution->fresh()->revision,
            );
            $this->fail('Diharapkan ValidationException saat lifecycle persisted tidak in_progress.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'PM untuk occurrence ini sudah diproses dan tidak dapat dimulai ulang.',
                collect($e->errors())->flatten()->first(),
            );
        }

        // Tidak ada item atau media baru yang dibuat; lifecycle tidak berubah.
        $this->assertSame($staleItemCount, (int) \DB::table('pm_execution_items')->where('pm_execution_id', $execution->id)->count());
        $this->assertSame($staleMediaCount, $execution->media()->count());
        $this->assertDatabaseHas('pm_executions', ['id' => $execution->id, 'status' => 'waiting_review']);
    }

    /**
     * Memastikan part dari context occurrence lain tidak dapat ditempelkan ke media.
     */
    public function test_media_upload_rejects_part_from_different_occurrence_checksheet(): void
    {
        $execution = $this->createExecutionWithMedia('in_progress');
        $otherChecksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-OTHER',
            'checksheet_name' => 'Checksheet Occurrence Lain',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $otherChecksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $otherChecksheet->id,
            'machine_id' => $this->machine->id,
            'created_by' => $this->admin->id,
        ]);
        $otherPart = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $otherChecksheetMachine->id,
            'part_name' => 'Part Occurrence Lain',
            'is_active' => true,
        ]);
        $filesBefore = Storage::disk('public')->files('pm-execution-media');

        try {
            app(PmExecutionMediaService::class)->storeForExecution(
                execution: $execution,
                part: $otherPart,
                file: UploadedFile::fake()->image('wrong-part.jpg', 640, 480),
                operator: $this->operator,
                note: 'Part dari occurrence lain.',
            );
            $this->fail('Part dari checksheet occurrence lain seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Part media tidak sesuai dengan checksheet occurrence PM.',
                collect($exception->errors())->flatten()->first(),
            );
        }

        // Tidak boleh ada row atau file ketika ownership part gagal diverifikasi.
        $this->assertDatabaseMissing('pm_execution_media', [
            'pm_execution_id' => $execution->id,
            'pm_checksheet_part_id' => $otherPart->id,
        ]);
        $this->assertSame($filesBefore, Storage::disk('public')->files('pm-execution-media'));
    }

    /**
     * Test A — upload media ditolak saat lifecycle tersimpan sudah keluar dari in_progress,
     * tanpa menyisakan baris media maupun file baru di storage.
     */
    public function test_stale_upload_is_rejected_without_persisting_media_row_or_file(): void
    {
        $staleExecution = $this->createExecutionWithMedia('in_progress', ['started_at' => now()->subMinutes(5)]);

        // Submit bersamaan memindahkan status tersimpan setelah kandidat execution diambil.
        PmExecution::query()->whereKey($staleExecution->id)->update(['status' => 'waiting_review']);
        PmScheduleDate::query()->whereKey($this->scheduleDate->id)->update(['status' => 'waiting_review']);

        $mediaCount = PmExecutionMedia::query()->where('pm_execution_id', $staleExecution->id)->count();
        $filesBefore = Storage::disk('public')->files('pm-execution-media');

        try {
            app(PmExecutionMediaService::class)->storeForExecution(
                execution: $staleExecution,
                part: $this->part,
                file: UploadedFile::fake()->image('stale-upload.jpg', 640, 480),
                operator: $this->operator,
                note: 'Upload saat PM sudah menunggu review.',
            );
            $this->fail('Upload media seharusnya ditolak untuk status waiting_review yang tersimpan.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Media PM hanya dapat diunggah saat PM sedang dikerjakan.',
                collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(
            $mediaCount,
            PmExecutionMedia::query()->where('pm_execution_id', $staleExecution->id)->count(),
        );
        $this->assertSame($filesBefore, Storage::disk('public')->files('pm-execution-media'));
        $this->assertDatabaseHas('pm_executions', ['id' => $staleExecution->id, 'status' => 'waiting_review']);
    }

    /**
     * Test F — kegagalan database selama deleteMedia memutar balik baris dan tidak membersihkan filesystem.
     * Satu-shot deleting observer pada PmExecutionMedia melempar RuntimeException di dalam transaksi.
     */
    public function test_db_failure_during_media_delete_rolls_back_row_and_skips_filesystem_cleanup(): void
    {
        $execution = $this->createExecutionWithMedia('in_progress', ['started_at' => now()->subMinutes(5)]);
        $media = $execution->media()->firstOrFail();

        // Daftarkan observer sekali pakai yang memaksa kegagalan DB di dalam transaksi.
        PmExecutionMedia::deleting(function (): void {
            throw new \RuntimeException('Simulasi kegagalan DB saat delete media.');
        });

        try {
            try {
                app(PmExecutionMediaService::class)->deleteMedia($media);
                $this->fail('Harus melempar exception saat DB gagal.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Simulasi kegagalan DB', $e->getMessage());
            }
        } finally {
            // Hapus seluruh listener pada model agar tidak bocor ke test lain.
            PmExecutionMedia::flushEventListeners();
        }

        // Baris media dan file tetap utuh karena transaksi di-rollback.
        $this->assertDatabaseHas('pm_execution_media', ['id' => $media->id]);
        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('public')->assertExists($media->original_file_path);
        $this->assertDatabaseHas('pm_executions', ['id' => $execution->id, 'status' => 'in_progress']);
    }

    /**
     * Test G — kegagalan cleanup filesystem setelah commit dicatat sebagai warning
     * tanpa membatalkan lifecycle yang sudah commit di database.
     * Pola: Storage::shouldReceive('disk') dengan disk mock dari Mockery, sesuai konvensi MachineService.
     */
    public function test_filesystem_cleanup_failure_after_media_delete_commit_logs_warning_without_lifecycle_rejection(): void
    {
        $execution = $this->createExecutionWithMedia('in_progress', ['started_at' => now()->subMinutes(5)]);
        $media = $execution->media()->firstOrFail();
        $filePath = $media->file_path;
        $originalFilePath = $media->original_file_path;

        // Mock disk: file_path mengembalikan false, original_file_path melempar exception.
        $diskMock = \Mockery::mock();
        $diskMock->shouldReceive('delete')
            ->once()
            ->with($filePath)
            ->andReturnFalse();
        $diskMock->shouldReceive('delete')
            ->once()
            ->with($originalFilePath)
            ->andThrow(new \RuntimeException('Disk I/O gagal pada cleanup.'));

        // Paksa Storage::disk('public') mengembalikan mock, sesuai konvensi MachineService.
        Storage::shouldReceive('disk')
            ->twice()
            ->with('public')
            ->andReturn($diskMock);

        Log::spy();

        // deleteMedia tidak boleh melempar; lifecycle tetap committed.
        app(PmExecutionMediaService::class)->deleteMedia($media);

        // Baris media terhapus dari database meskipun cleanup filesystem gagal.
        $this->assertDatabaseMissing('pm_execution_media', ['id' => $media->id]);
        $this->assertDatabaseHas('pm_executions', ['id' => $execution->id, 'status' => 'in_progress']);

        // Dua warning harus dicatat: satu false-return, satu exception.
        Log::shouldHaveReceived('warning')
            ->twice();
    }

    // ====================================================================
    // ADR-004 Slice 1 — Canonical Serialization + Lifecycle Contract
    // ====================================================================

    /**
     * S1-01: Start terjadwal pertama → tepat satu execution, occurrence in_progress.
     */
    public function test_s1_first_scheduled_start_creates_exactly_one_execution(): void
    {
        $service = app(PmExecutionService::class);
        $execution = $service->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );

        $this->assertSame('in_progress', $execution->status);
        $this->assertSame(1, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
        $this->assertSame('in_progress', $this->scheduleDate->fresh()->status);
    }

    /**
     * S1-02: Operator yang sama retry → reuse execution ID, count tetap 1.
     */
    public function test_s1_same_operator_retry_reuses_execution(): void
    {
        $service = app(PmExecutionService::class);
        $first = $service->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );
        $second = $service->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-03: Operator berbeda retry → reuse ID yang sama, operator asli tetap.
     */
    public function test_s1_different_operator_retry_reuses_same_execution_preserves_original_operator(): void
    {
        $service = app(PmExecutionService::class);
        $first = $service->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );

        $otherOperator = User::query()->create([
            'name' => 'Operator Lain',
            'username' => 'operator.lain',
            'password' => Hash::make('password'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $second = $service->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $otherOperator,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame($this->operator->id, $second->fresh()->operator_id);
        $this->assertSame(1, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-04: Start, Simpan Draft, dan media-upload memakai execution canonical yang sama.
     * BR-PM-EXEC-004: Start-lah yang membuat execution; draft dan media upload
     * hanya mengikuti execution tersebut tanpa membuat row baru.
     */
    public function test_s1_all_entry_points_use_same_canonical_execution(): void
    {
        // Entry point 1: Mulai PM (HTTP) membuat execution canonical.
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $started = PmExecution::query()->firstOrFail();

        // Entry point 2: Simpan Draft memutasi execution yang sama.
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $started->revision,
                'execution_action' => [$this->itemIdFor($this->actionStandard) => 'OK'],
                'execution_number' => [$this->itemIdFor($this->numberStandard) => '60', $this->itemIdFor($this->rangeStandard) => '120'],
                'part_notes' => [$this->part->id => 'Catatan draft S1-04'],
            ])
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");
        $this->assertSame(1, PmExecution::query()->count());

        // Entry point 3: media upload terikat pada execution yang sama.
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/media/upload", [
                'part_id' => $this->part->id,
                'part_note' => 'Foto draft S1-04',
                'media_file' => UploadedFile::fake()->image('canonical.jpg', 640, 480),
            ])
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $media = PmExecutionMedia::query()->firstOrFail();
        $this->assertSame($started->id, (int) $media->pm_execution_id);
        $this->assertSame(
            1,
            PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count(),
        );
    }

    /**
     * S1-05: Existing in_progress execution tetap digunakan ulang.
     */
    public function test_s1_existing_in_progress_reused(): void
    {
        $existing = $this->createExecutionWithMedia('in_progress', ['started_at' => now()->subMinutes(10)]);

        $service = app(PmExecutionService::class);
        $resolved = $service->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame(1, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-06: waiting_review menolak start (zero mutation).
     */
    public function test_s1_waiting_review_rejects_start_with_zero_mutation(): void
    {
        $existing = $this->createExecutionWithMedia('waiting_review', [
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ]);
        // Occurrence stale/eligible: resolver must reject from execution lifecycle.
        $this->scheduleDate->forceFill([
            'status' => 'scheduled',
            'status_changed_at' => null,
        ])->save();

        $service = app(PmExecutionService::class);

        try {
            $service->startExecutionOnly(
                request: $this->s1Request(),
                machine: $this->machine,
                operator: $this->operator,
            );
            $this->fail('Start harus ditolak saat ada execution waiting_review.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'sudah',
                collect($e->errors())->flatten()->first(),
            );
        }

        $this->assertSame(1, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
        $this->assertSame('waiting_review', $existing->fresh()->status);
    }

    /**
     * S1-07: approved menolak start (zero mutation).
     */
    public function test_s1_approved_rejects_start_with_zero_mutation(): void
    {
        $existing = $this->createExecutionWithMedia('approved', [
            'started_at' => now()->subHours(2),
            'submitted_at' => now()->subHour(),
            'approved_at' => now()->subMinutes(30),
            'approved_by' => $this->admin->id,
            'approved_by_name_snapshot' => $this->admin->name,
        ]);
        // Occurrence stale/eligible: resolver must reject from execution lifecycle.
        $this->scheduleDate->forceFill([
            'status' => 'scheduled',
            'status_changed_at' => null,
        ])->save();

        $service = app(PmExecutionService::class);

        try {
            $service->startExecutionOnly(
                request: $this->s1Request(),
                machine: $this->machine,
                operator: $this->operator,
            );
            $this->fail('Start harus ditolak saat ada execution approved.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'sudah',
                collect($e->errors())->flatten()->first(),
            );
        }

        $this->assertSame(1, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
        $this->assertSame('approved', $existing->fresh()->status);
    }

    /**
     * S1-08: Soft-deleted execution memblokir pembuatan baru (bukti historis).
     */
    public function test_s1_soft_deleted_execution_blocks_replacement(): void
    {
        $existing = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ]);
        $existing->delete(); // soft-delete

        $service = app(PmExecutionService::class);

        try {
            $service->startExecutionOnly(
                request: $this->s1Request(),
                machine: $this->machine,
                operator: $this->operator,
            );
            $this->fail('Start harus ditolak saat ada soft-deleted execution.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'histori',
                mb_strtolower(collect($e->errors())->flatten()->first()),
            );
        }

        // Tetap hanya satu execution (yang soft-deleted)
        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-09: Legacy duplicate (>1 rows) fail closed — tidak memilih newest/oldest.
     */
    public function test_s1_legacy_duplicate_executions_fail_closed(): void
    {
        // S2: named unique index membuat row duplikat tidak mungkin dibuat lewat jalur normal.
        // Test legacy ini tetap memvalidasi kondisi pre-constraint dengan dua row nyata:
        // turunkan sementara hanya named unique index, pulihkan lewat finally di akhir test.
        Schema::table('pm_executions', function (Blueprint $table): void {
            $table->dropUnique('pm_executions_pm_schedule_date_id_unique');
        });

        $first = null;
        $second = null;

        try {
            // Buat dua execution secara manual untuk simulasi data legacy
            $first = PmExecution::query()->create([
                'pm_schedule_date_id' => $this->scheduleDate->id,
                'machine_id' => $this->machine->id,
                'operator_id' => $this->operator->id,
                'operator_name_snapshot' => $this->operator->name,
                'status' => 'in_progress',
                'started_at' => now()->subHour(),
            ]);
            $second = PmExecution::query()->create([
                'pm_schedule_date_id' => $this->scheduleDate->id,
                'machine_id' => $this->machine->id,
                'operator_id' => $this->operator->id,
                'operator_name_snapshot' => $this->operator->name,
                'status' => 'in_progress',
                'started_at' => now()->subMinutes(30),
            ]);
            $this->scheduleDate->forceFill(['status' => 'in_progress', 'status_changed_at' => now()])->save();

            $service = app(PmExecutionService::class);

            try {
                $service->startExecutionOnly(
                    request: $this->s1Request(),
                    machine: $this->machine,
                    operator: $this->operator,
                );
                $this->fail('Legacy duplicate harus gagal closed.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString(
                    'duplikat',
                    mb_strtolower(collect($e->errors())->flatten()->first()),
                );
            }
        } finally {
            // Hard delete fixture duplikat (row soft-deleted pun tetap menempati unique index),
            // lalu pulihkan named unique index agar invariant Slice 2 tidak bocor ke test lain.
            if ($first !== null || $second !== null) {
                PmExecution::withTrashed()
                    ->whereIn('id', array_filter([$first?->id, $second?->id]))
                    ->forceDelete();
            }

            Schema::table('pm_executions', function (Blueprint $table): void {
                $table->unique('pm_schedule_date_id', 'pm_executions_pm_schedule_date_id_unique');
            });
        }
    }

    /**
     * S1-11: Machine mismatch tetap terdeteksi berdasarkan schedule-date identity.
     * Resolver tidak boleh membuat replacement untuk row historis yang salah parent.
     */
    public function test_s1_machine_mismatch_execution_blocks_replacement(): void
    {
        $otherMachine = Machine::query()->create([
            'location_id' => $this->machine->location_id,
            'machine_code' => 'UC-002',
            'machine_name' => 'MOTOR SERVO B',
            'qr_token' => 'qr-uc-002',
            'is_active' => true,
        ]);

        $existing = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $otherMachine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ]);

        $service = app(PmExecutionService::class);

        try {
            $service->startExecutionOnly(
                request: $this->s1Request(),
                machine: $this->machine,
                operator: $this->operator,
            );
            $this->fail('Start harus ditolak saat machine execution mismatch dengan occurrence.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'tidak sesuai',
                mb_strtolower(collect($e->errors())->flatten()->first()),
            );
        }

        $this->assertDatabaseHas('pm_executions', [
            'id' => $existing->id,
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $otherMachine->id,
            'status' => 'in_progress',
        ]);
        $this->assertSame(
            1,
            PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count(),
        );
    }

    /**
     * Membuat Request dengan session store agar ActivityLogService dapat dipanggil
     * langsung dari service tanpa middleware web penuh (ADR-004 Slice 1).
     */
    private function s1Request(): Request
    {
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    /**
     * BR-PM-EXEC-004: memulai execution canonical lewat HTTP sebagai prasyarat
     * aksi hasil (draft/submit/media), lalu mengembalikan execution tersebut.
     */
    private function startCanonicalExecution(): PmExecution
    {
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        return PmExecution::query()->firstOrFail();
    }

    /**
     * Snapshot defensif: mutasi master setelah Mulai PM tidak boleh mengubah
     * tampilan, validasi, maupun kunci input executor yang sudah berjalan.
     */
    public function test_master_mutation_after_start_uses_frozen_snapshot(): void
    {
        $execution = $this->startCanonicalExecution();

        $itemIdAction = $this->itemIdFor($this->actionStandard);
        $itemIdNumber = $this->itemIdFor($this->numberStandard);

        // Standard master berubah setelah Start: rename, nonaktif, target/bounds baru.
        $this->numberStandard->forceFill([
            'standard_name' => 'Suhu Motor (baru)',
            'target_value' => 21,
            'is_active' => false,
        ])->save();
        $this->rangeStandard->forceFill(['min_value' => 500, 'max_value' => 600])->save();
        $this->actionStandard->forceFill(['standard_name' => 'Kondisi Gearbox (baru)', 'is_active' => false])->save();

        // Executor harus tetap menampilkan definisi beku (bukan master).
        $response = $this->actingAs($this->operator)
            ->get("/pm/executor/{$this->machine->machine_code}");
        $response->assertOk();
        $response->assertSee('Kondisi Gearbox', false);
        $response->assertSee('Suhu Motor', false);
        $response->assertDontSee('Suhu Motor (baru)', false);
        $response->assertDontSee('Kondisi Gearbox (baru)', false);

        // Draft memakai kunci item execution, bukan standard master. Semua
        // standard beku wajib tetap dikirim karena is_required berasal dari snapshot.
        $response = $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$itemIdAction => 'OK'],
                'execution_number' => [
                    $itemIdNumber => 60,
                    $this->itemIdFor($this->rangeStandard) => 120,
                ],
            ]);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        // Warning dihitung dari definisi beku: target 60 dan range 114-190,
        // bukan target 21 dan range 500-600 milik master yang sudah berubah.
        $itemNumber = PmExecutionItem::query()->findOrFail($itemIdNumber);
        $this->assertSame(60.0, (float) $itemNumber->number_value);
        $this->assertFalse((bool) $itemNumber->is_warning);
        $itemRange = PmExecutionItem::query()->findOrFail($this->itemIdFor($this->rangeStandard));
        $this->assertFalse((bool) $itemRange->is_warning);

        // Submit juga lolos walau master sudah dihapus permanen.
        $this->actionStandard->delete();
        $this->numberStandard->delete();

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/submit", [
                'expected_revision' => (int) $execution->fresh()->revision,
                'execution_action' => [$itemIdAction => 'OK'],
                'execution_number' => [
                    $itemIdNumber => 60,
                    $this->itemIdFor($this->rangeStandard) => 120,
                ],
            ])
            ->assertRedirect("/machines/{$this->machine->machine_code}");

        $execution = $execution->fresh();
        $this->assertSame('waiting_review', $execution->status);

        $items = PmExecutionItem::query()->where('pm_execution_id', $execution->id)->orderBy('id')->get();
        $this->assertSame([$itemIdAction, $itemIdNumber, $this->itemIdFor($this->rangeStandard)], $items->pluck('id')->all());
        $this->assertSame('Kondisi Gearbox', $items->firstWhere('id', $itemIdAction)?->standard_name_snapshot);
        $this->assertSame('Suhu Motor', $items->firstWhere('id', $itemIdNumber)?->standard_name_snapshot);
    }

    public function test_snapshot_required_and_action_options_drive_validation_after_master_change(): void
    {
        $execution = $this->startCanonicalExecution();

        $itemIdAction = $this->itemIdFor($this->actionStandard);

        // Master berubah: opsi action baru ditambah dan required dimatikan.
        $this->actionStandard->forceFill([
            'action_options' => ['OK', 'GANTI', 'REPAIR', 'DITUNDA'],
            'is_required' => false,
            'is_active' => false,
        ])->save();

        // Value di luar pilihan beku ditolak walau ada di master baru.
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$itemIdAction => 'DITUNDA'],
            ])
            ->assertSessionHasErrors();

        // Required beku diperiksa saat Submit, bukan saat Draft disimpan.
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/submit", [
                'expected_revision' => (int) $execution->revision,
            ])
            ->assertSessionHasErrors();

        // Pilihan beku diterima pada Draft meski nomor wajib belum dikirim.
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$itemIdAction => 'OK'],
                'execution_number' => [$this->itemIdFor($this->numberStandard) => 60, $this->itemIdFor($this->rangeStandard) => 120],
            ])
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $item = PmExecutionItem::query()->findOrFail($itemIdAction);
        $this->assertSame('OK', $item->action_value);
    }

    public function test_foreign_execution_item_ids_fail_closed(): void
    {
        $execution = $this->startCanonicalExecution();
        $itemIdAction = $this->itemIdFor($this->actionStandard);

        // Execution lain pada occurrence berbeda membuat item asing.
        $foreignScheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->scheduleDate->pm_schedule_id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'status' => 'scheduled',
        ]);
        $foreignExecution = PmExecution::query()->create([
            'pm_schedule_date_id' => $foreignScheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $foreignItem = PmExecutionItem::query()->create([
            'pm_execution_id' => $foreignExecution->id,
            'pm_checksheet_part_id' => $this->part->id,
            'pm_checksheet_standard_id' => $this->numberStandard->id,
            'part_name_snapshot' => 'Asing',
            'standard_name_snapshot' => 'Item Asing',
            'input_type_snapshot' => 'number',
            'min_value_snapshot' => 0,
            'max_value_snapshot' => 100,
            'is_required_snapshot' => false,
        ]);

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_number' => [$foreignItem->id => 50],
            ])
            ->assertSessionHasErrors();

        // Item canonical tidak terpengaruh oleh percobaan asing.
        $this->assertNull(PmExecutionItem::query()->find($itemIdAction)?->action_value);
        $this->assertNull($foreignItem->fresh()->number_value);
    }

    public function test_item_ids_stay_stable_across_draft_and_submit(): void
    {
        $execution = $this->startCanonicalExecution();

        $idsBefore = PmExecutionItem::query()->where('pm_execution_id', $execution->id)->orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $idsBefore);

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$idsBefore[0] => 'GANTI'],
                'execution_number' => [$idsBefore[1] => 61, $idsBefore[2] => 130],
            ])
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/submit", [
                'expected_revision' => (int) $execution->fresh()->revision,
                'execution_action' => [$idsBefore[0] => 'OK'],
                'execution_number' => [$idsBefore[1] => 62, $idsBefore[2] => 140],
            ])
            ->assertRedirect("/machines/{$this->machine->machine_code}");

        $idsAfter = PmExecutionItem::query()->where('pm_execution_id', $execution->id)->orderBy('id')->pluck('id')->all();
        $this->assertSame($idsBefore, $idsAfter);

        $item = PmExecutionItem::query()->findOrFail($idsBefore[0]);
        $this->assertSame('OK', $item->action_value);
        $this->assertSame('waiting_review', $execution->fresh()->status);
    }

    public function test_master_action_pivot_changes_after_start_do_not_alter_frozen_options(): void
    {
        $execution = $this->startCanonicalExecution();
        $itemIdAction = $this->itemIdFor($this->actionStandard);

        // Setelah Start, Master Action baru dipasang pada standard tersebut.
        $newAction = PmAction::query()->create(['action_label' => 'DITUNDA', 'is_active' => true]);
        $this->actionStandard->actions()->sync([$newAction->id => ['position' => 1]]);
        $this->actionStandard->forceFill(['action_options' => ['DITUNDA']])->save();

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");
        $response->assertOk();
        $response->assertSee('data-option-value="OK"', false);
        $response->assertDontSee('data-option-value="DITUNDA"', false);

        // Pilihan beku tetap satu-satunya yang valid.
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$itemIdAction => 'DITUNDA'],
            ])
            ->assertSessionHasErrors();

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$itemIdAction => 'REPAIR'],
                'execution_number' => [$this->itemIdFor($this->numberStandard) => 60, $this->itemIdFor($this->rangeStandard) => 120],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('REPAIR', PmExecutionItem::query()->findOrFail($itemIdAction)->action_value);
    }

    public function test_master_standard_added_after_start_is_not_part_of_frozen_checklist(): void
    {
        $execution = $this->startCanonicalExecution();

        PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $this->part->id,
            'standard_name' => 'Standard Baru Setelah Start',
            'input_type' => 'number',
            'target_value' => 5,
            'is_required' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");
        $response->assertOk();
        $response->assertDontSee('Standard Baru Setelah Start', false);

        // Submit hanya menuntut tiga definisi beku.
        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/submit", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$this->itemIdFor($this->actionStandard) => 'OK'],
                'execution_number' => [$this->itemIdFor($this->numberStandard) => 60, $this->itemIdFor($this->rangeStandard) => 120],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, PmExecutionItem::query()->where('pm_execution_id', $execution->id)->count());
    }

    public function test_frozen_execution_still_binds_media_to_master_part_id(): void
    {
        $execution = $this->startCanonicalExecution();

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/media/upload", [
                'part_id' => $this->part->id,
                'part_note' => 'Foto snapshot',
                'media_file' => UploadedFile::fake()->image('snapshot.jpg', 320, 240),
            ])
            ->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $this->assertDatabaseHas('pm_execution_media', [
            'pm_execution_id' => $execution->id,
            'pm_checksheet_part_id' => $this->part->id,
        ]);
    }

    /**
     * S1-10: canonicalExecution() relation pada PmScheduleDate mengembalikan single row termasuk soft-deleted.
     */
    public function test_s1_canonical_execution_relation_includes_soft_deleted(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ]);

        // Sebelum soft-delete
        $canonical = $this->scheduleDate->canonicalExecution;
        $this->assertNotNull($canonical);
        $this->assertSame($execution->id, $canonical->id);

        // Setelah soft-delete
        $execution->delete();
        $fresh = $this->scheduleDate->fresh();
        $this->assertNotNull($fresh->canonicalExecution);
        $this->assertSame($execution->id, $fresh->canonicalExecution->id);
    }

    /**
     * S1-11: Authorization tetap tidak berubah — admin dan guest tetap ditolak.
     */
    public function test_s1_authorization_unchanged(): void
    {
        // Admin cannot start
        $this->actingAs($this->admin)
            ->post("/pm/executor/{$this->machine->machine_code}/start", [
                'execution_action' => [$this->actionStandard->id => 'OK'],
                'execution_number' => [
                    $this->numberStandard->id => 60,
                    $this->rangeStandard->id => 120,
                ],
            ])
            ->assertRedirect('/403');

        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-12: Occurrence transition + next-date behavior tidak berubah setelah canonical resolver.
     */
    public function test_s1_occurrence_transition_and_next_date_unchanged(): void
    {
        // BR-PM-EXEC-004: Start membuat execution canonical; submit menuliskan hasilnya.
        $this->startCanonicalExecution();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => 0,
            'execution_action' => [$this->itemIdFor($this->actionStandard) => 'OK'],
            'execution_number' => [
                $this->itemIdFor($this->numberStandard) => 60,
                $this->itemIdFor($this->rangeStandard) => 120,
            ],
        ])->assertRedirect("/machines/{$this->machine->machine_code}");

        $execution = PmExecution::query()->firstOrFail();
        $this->assertSame('waiting_review', $execution->status);
        $this->assertSame('waiting_review', $this->scheduleDate->fresh()->status);

        // Next schedule date harus ada
        $this->assertTrue(
            PmScheduleDate::query()
                ->where('pm_schedule_id', $this->scheduleDate->pm_schedule_id)
                ->where('machine_id', $this->machine->id)
                ->whereDate('scheduled_date', now()->addDay()->toDateString())
                ->where('status', 'scheduled')
                ->exists(),
        );
    }

    /**
     * Slice A: eksekusi baru menangkap identitas mesin, lokasi, dan checksheet dari parent authoritative.
     */
    public function test_slice_a_new_execution_persists_transaction_identity_snapshots(): void
    {
        $execution = app(PmExecutionService::class)->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );

        $this->assertSame('UC-001', $execution->machine_code_snapshot);
        $this->assertSame('MOTOR SERVO', $execution->machine_name_snapshot);
        $this->assertSame('LOC-01', $execution->location_code_snapshot);
        $this->assertSame('Upcast', $execution->location_name_snapshot);
        $this->assertSame('PM-CH-UC-001', $execution->checksheet_code_snapshot);
        $this->assertSame('Checksheet UC-001', $execution->checksheet_name_snapshot);
        $this->assertSame($this->machine->id, $execution->machine_id);
        $this->assertSame($this->scheduleDate->id, $execution->pm_schedule_date_id);
    }

    /**
     * Slice A: reuse canonical tidak pernah menulis ulang snapshot transaction identity.
     */
    public function test_slice_a_canonical_reuse_keeps_original_identity_snapshots(): void
    {
        $service = app(PmExecutionService::class);
        $first = $service->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
        $original = $first->only([
            'machine_code_snapshot', 'machine_name_snapshot',
            'location_code_snapshot', 'location_name_snapshot',
            'checksheet_code_snapshot', 'checksheet_name_snapshot',
        ]);

        $this->machine->forceFill(['machine_code' => 'UC-RENAMED', 'machine_name' => 'MOTOR RENAMED'])->save();
        $this->machine->location->forceFill(['location_code' => 'LOC-RENAMED', 'location_name' => 'Lokasi Renamed'])->save();
        $this->scheduleDate->schedule->checksheetMachine->checksheet->forceFill([
            'checksheet_code' => 'PM-RENAMED',
            'checksheet_name' => 'Checksheet Renamed',
        ])->save();

        $second = $service->startExecutionOnly($this->s1Request(), $this->machine->fresh(), $this->operator);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($original, $second->fresh()->only(array_keys($original)));
    }

    /**
     * SQLite menguji kompatibilitas writer/reader, bukan kapasitas tipe MySQL LONGTEXT.
     */
    public function test_history_writer_preserves_nullable_scalar_and_large_values_without_parsing(): void
    {
        $execution = $this->startCanonicalExecution();
        $history = app(PmExecutionHistoryService::class);
        $largeValue = str_repeat('HASIL, OLI ', 7000);

        $history->recordMany($execution, $this->operator, [
            ['field_name' => 'Hasil PM', 'old_value' => null, 'new_value' => 'OK, LUBRIKASI', 'change_note' => 'Draft'],
            ['field_name' => 'Hasil PM', 'old_value' => 'OK, LUBRIKASI', 'new_value' => $largeValue, 'change_note' => 'Draft'],
        ]);
        $history->record($execution, $this->operator, 'Hasil PM', $largeValue, null, 'Draft');

        $rows = $execution->history()->orderBy('id')->get();
        $this->assertNull($rows[0]->old_value);
        $this->assertSame('OK, LUBRIKASI', $rows[0]->new_value);
        $this->assertSame('OK, LUBRIKASI', $rows[1]->old_value);
        $this->assertSame($largeValue, $rows[1]->new_value);
        $this->assertSame($largeValue, $rows[2]->old_value);
        $this->assertNull($rows[2]->new_value);
    }

    public function test_multiple_legacy_scalar_survives_draft_that_only_changes_number(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $actionItem = PmExecutionItem::query()->findOrFail($this->itemIdFor($this->actionStandard));
        $actionItem->forceFill(['action_value' => ' ok '])->save();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_number' => [$this->itemIdFor($this->numberStandard) => 60],
        ])->assertSessionHasNoErrors();

        $this->assertSame(' ok ', $actionItem->fresh()->action_value);
        $context = app(PmExecutionService::class)->getExecutorContext($this->machine->fresh());
        $this->assertSame(' ok ', $context['parts'][0]['items'][0]['action_value']);
        $this->assertSame([], $context['parts'][0]['items'][0]['selected_action_values']);
        $this->assertDatabaseMissing('pm_execution_item_action_results', ['pm_execution_item_id' => $actionItem->id]);
        $this->assertSame('60.00', PmExecutionItem::query()->findOrFail($this->itemIdFor($this->numberStandard))->number_value);
    }

    public function test_multiple_legacy_scalar_satisfies_required_submit_when_action_is_omitted(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $actionItem = PmExecutionItem::query()->findOrFail($this->itemIdFor($this->actionStandard));
        $actionItem->forceFill(['action_value' => 'OK'])->save();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => (int) $execution->revision,
            'execution_number' => [
                $this->itemIdFor($this->numberStandard) => 60,
                $this->itemIdFor($this->rangeStandard) => 120,
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('OK', $actionItem->fresh()->action_value);
        $this->assertSame('waiting_review', $execution->fresh()->status);
    }

    public function test_multiple_legacy_scalar_converts_only_on_explicit_set_with_frozen_ordered_history(): void
    {
        $this->actionStandard->update([
            'selection_mode' => 'multiple',
            'action_options' => ['OK', 'TAMBAH, OLI', 'LUBRICATION'],
        ]);
        $execution = $this->startCanonicalExecution();
        $actionItem = PmExecutionItem::query()->findOrFail($this->itemIdFor($this->actionStandard));
        $actionItem->forceFill(['action_value' => 'OK'])->save();
        $this->actionStandard->update(['action_options' => ['STALE']]);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$actionItem->id => ['LUBRICATION', 'TAMBAH, OLI']],
        ])->assertSessionHasNoErrors();

        $this->assertNull($actionItem->fresh()->action_value);
        $this->assertSame([
            ['selected_label_snapshot' => 'TAMBAH, OLI', 'option_position_snapshot' => 2],
            ['selected_label_snapshot' => 'LUBRICATION', 'option_position_snapshot' => 3],
        ], DB::table('pm_execution_item_action_results')->where('pm_execution_item_id', $actionItem->id)
            ->orderBy('option_position_snapshot')->get(['selected_label_snapshot', 'option_position_snapshot'])
            ->map(fn ($row): array => (array) $row)->all());
        $change = $execution->history()->where('field_name', 'like', 'Hasil PM%')->latest('id')->firstOrFail();
        $this->assertSame('OK', $change->old_value);
        $this->assertSame('["TAMBAH, OLI","LUBRICATION"]', $change->new_value);

        $context = app(PmExecutionService::class)->getExecutorContext($this->machine->fresh());
        $this->assertSame(['TAMBAH, OLI', 'LUBRICATION'], $context['parts'][0]['items'][0]['selected_action_values']);
    }

    public function test_multiple_legacy_scalar_can_be_cleared_without_backfill(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $actionItem = PmExecutionItem::query()->findOrFail($this->itemIdFor($this->actionStandard));
        $actionItem->forceFill(['action_value' => 'OK'])->save();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_clear' => [$actionItem->id => 1],
        ])->assertSessionHasNoErrors();

        $this->assertNull($actionItem->fresh()->action_value);
        $this->assertDatabaseMissing('pm_execution_item_action_results', ['pm_execution_item_id' => $actionItem->id]);
        $change = $execution->history()->where('field_name', 'like', 'Hasil PM%')->latest('id')->firstOrFail();
        $this->assertSame('OK', $change->old_value);
        $this->assertNull($change->new_value);
    }

    public function test_multiple_ambiguous_legacy_snapshot_keeps_scalar_but_rejects_ambiguous_set(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $actionItem = PmExecutionItem::query()->findOrFail($this->itemIdFor($this->actionStandard));
        $actionItem->forceFill([
            'action_options_snapshot' => ['OK', 'OK', 'NG'],
            'action_value' => 'OK',
        ])->save();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_number' => [$this->itemIdFor($this->numberStandard) => 60],
        ])->assertSessionHasNoErrors();
        $this->assertSame('OK', $actionItem->fresh()->action_value);
        $context = app(PmExecutionService::class)->getExecutorContext($this->machine->fresh());
        $this->assertSame('OK', $context['parts'][0]['items'][0]['action_value']);
        $this->assertSame([], $context['parts'][0]['items'][0]['selected_action_values']);

        $revision = (int) $execution->fresh()->revision;
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => $revision,
            'execution_action' => [$actionItem->id => ['OK']],
        ])->assertSessionHasErrors();

        $this->assertSame('OK', $actionItem->fresh()->action_value);
        $this->assertSame($revision, (int) $execution->fresh()->revision);
        $this->assertDatabaseMissing('pm_execution_item_action_results', ['pm_execution_item_id' => $actionItem->id]);
    }

    public function test_ambiguous_legacy_snapshot_can_set_a_uniquely_identified_frozen_choice(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $actionItem = PmExecutionItem::query()->findOrFail($this->itemIdFor($this->actionStandard));
        $actionItem->forceFill([
            'action_options_snapshot' => ['OK', 'OK', 'NG'],
            'action_value' => 'OK',
        ])->save();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$actionItem->id => ['NG']],
        ])->assertSessionHasNoErrors();

        $this->assertNull($actionItem->fresh()->action_value);
        $this->assertDatabaseHas('pm_execution_item_action_results', [
            'pm_execution_item_id' => $actionItem->id,
            'selected_label_snapshot' => 'NG',
            'option_position_snapshot' => 3,
        ]);
    }

    public function test_multiple_draft_keeps_selected_rows_on_numeric_patch_and_reopens_in_frozen_order(): void
    {
        $this->actionStandard->update([
            'selection_mode' => 'multiple',
            'action_options' => ['OK', 'TAMBAH OLI', 'LUBRIKASI'],
        ]);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => ['LUBRIKASI', 'TAMBAH OLI']],
            'execution_number' => [$this->itemIdFor($this->numberStandard) => 10],
        ])->assertSessionHasNoErrors();

        PmExecutionItem::query()->findOrFail($itemId)->forceFill(['action_value' => 'STALE'])->save();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->fresh()->revision,
            'execution_number' => [$this->itemIdFor($this->numberStandard) => 12],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['TAMBAH OLI', 'LUBRIKASI'], DB::table('pm_execution_item_action_results')
            ->where('pm_execution_item_id', $itemId)->orderBy('option_position_snapshot')
            ->pluck('selected_label_snapshot')->all());
        $this->assertNull(PmExecutionItem::query()->findOrFail($itemId)->action_value);
        $this->assertSame('12.00', PmExecutionItem::query()->findOrFail($this->itemIdFor($this->numberStandard))->number_value);
        $context = app(PmExecutionService::class)->getExecutorContext($this->machine->fresh());
        $this->assertSame(['TAMBAH OLI', 'LUBRIKASI'], $context['parts'][0]['items'][0]['selected_action_values']);
    }

    public function test_multiple_note_only_patch_does_not_create_false_result_history(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => ['OK']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->fresh()->revision,
            'part_notes' => [$this->part->id => 'Diperiksa ulang'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $execution->history()
            ->where('field_name', 'like', "Hasil PM [P{$this->part->id}/S{$this->actionStandard->id}]%")
            ->count());
        $this->assertSame(['OK'], DB::table('pm_execution_item_action_results')
            ->where('pm_execution_item_id', $itemId)->pluck('selected_label_snapshot')->all());
    }

    public function test_multiple_draft_rejects_empty_unknown_and_duplicate_arrays_without_clearing_results(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);

        foreach ([[], ['UNKNOWN'], ['OK', ' ok ']] as $invalid) {
            $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
                'expected_revision' => (int) $execution->revision,
                'execution_action' => [$itemId => $invalid],
            ])->assertSessionHasErrors();
            $this->assertNull(PmExecutionItem::query()->findOrFail($itemId)->action_value);
            $this->assertSame(0, (int) $execution->fresh()->revision);
        }
    }

    public function test_multiple_required_submit_merges_draft_and_rejected_clear_is_atomic(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => ['OK', 'GANTI']],
            'execution_number' => [
                $this->itemIdFor($this->numberStandard) => 60,
                $this->itemIdFor($this->rangeStandard) => 120,
            ],
        ])->assertSessionHasNoErrors();

        $revision = (int) $execution->fresh()->revision;
        $historyCount = $execution->history()->count();
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => $revision,
            'execution_clear' => [$itemId => 1],
            'execution_number' => [$this->itemIdFor($this->numberStandard) => 70],
        ])->assertSessionHasErrors();

        $this->assertSame(['OK', 'GANTI'], DB::table('pm_execution_item_action_results')
            ->where('pm_execution_item_id', $itemId)->orderBy('option_position_snapshot')
            ->pluck('selected_label_snapshot')->all());
        $this->assertSame('60.00', PmExecutionItem::query()->findOrFail($this->itemIdFor($this->numberStandard))->number_value);
        $this->assertSame('in_progress', $execution->fresh()->status);
        $this->assertSame($revision, (int) $execution->fresh()->revision);
        $this->assertSame($historyCount, $execution->history()->count());
        $this->assertDatabaseMissing('notifications', ['notification_type' => 'pm_waiting_review', 'related_id' => $execution->id]);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/submit", [
            'expected_revision' => $revision,
        ])->assertSessionHasNoErrors();
        $this->assertSame('waiting_review', $execution->fresh()->status);
    }

    public function test_single_and_numeric_draft_patch_keeps_omitted_values_and_clears_explicit_values(): void
    {
        $execution = $this->startCanonicalExecution();
        $actionId = $this->itemIdFor($this->actionStandard);
        $numberId = $this->itemIdFor($this->numberStandard);
        $rangeId = $this->itemIdFor($this->rangeStandard);
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$actionId => 'OK'],
            'execution_number' => [$numberId => 60, $rangeId => 120],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->fresh()->revision,
            'execution_number' => [$rangeId => ''],
        ])->assertSessionHasNoErrors();
        $this->assertSame('OK', PmExecutionItem::query()->findOrFail($actionId)->action_value);
        $this->assertSame('60.00', PmExecutionItem::query()->findOrFail($numberId)->number_value);
        $this->assertNull(PmExecutionItem::query()->findOrFail($rangeId)->number_value);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->fresh()->revision,
            'execution_action' => [$actionId => ''],
            'execution_number' => [$numberId => '', $rangeId => 125],
        ])->assertSessionHasNoErrors();
        $this->assertNull(PmExecutionItem::query()->findOrFail($actionId)->action_value);
        $this->assertNull(PmExecutionItem::query()->findOrFail($numberId)->number_value);
        $this->assertSame('125.00', PmExecutionItem::query()->findOrFail($rangeId)->number_value);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->fresh()->revision,
            'execution_action' => [$actionId => 'GANTI'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('GANTI', PmExecutionItem::query()->findOrFail($actionId)->action_value);
    }

    public function test_normalized_multiple_result_can_be_cleared_explicitly_and_set_again(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => ['OK', 'GANTI']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->fresh()->revision,
            'execution_clear' => [$itemId => 1],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('pm_execution_item_action_results', ['pm_execution_item_id' => $itemId]);
        $this->assertNull(PmExecutionItem::query()->findOrFail($itemId)->action_value);
        $change = $execution->history()->where('field_name', 'like', 'Hasil PM%')->latest('id')->firstOrFail();
        $this->assertSame('["OK","GANTI"]', $change->old_value);
        $this->assertNull($change->new_value);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->fresh()->revision,
            'execution_action' => [$itemId => ['REPAIR']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['REPAIR'], DB::table('pm_execution_item_action_results')
            ->where('pm_execution_item_id', $itemId)->pluck('selected_label_snapshot')->all());
    }

    public function test_multiple_scalar_patch_is_rejected_without_mutation(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => 'OK'],
        ])->assertSessionHasErrors();
        $this->assertDatabaseMissing('pm_execution_item_action_results', ['pm_execution_item_id' => $itemId]);
        $this->assertNull(PmExecutionItem::query()->findOrFail($itemId)->action_value);
        $this->assertSame(0, (int) $execution->fresh()->revision);
    }

    public function test_multiple_executor_reopens_unique_mixed_case_selected_identity(): void
    {
        $this->actionStandard->update([
            'selection_mode' => 'multiple',
            'action_options' => ['OK', 'TAMBAH OLI', 'LUBRIKASI'],
        ]);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);
        $item = PmExecutionItem::query()->findOrFail($itemId);
        $item->forceFill(['action_options_snapshot' => ['Ok', 'TAMBAH OLI', 'LUBRIKASI']])->save();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => ['OK']],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertOk();
        $body = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<input(?=[^>]*type="checkbox")(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*value="OK")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*type="checkbox")(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*value="TAMBAH OLI")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*type="checkbox")(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*value="LUBRIKASI")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertSame('Ok', DB::table('pm_execution_item_action_results')->where('pm_execution_item_id', $itemId)->value('selected_label_snapshot'));
    }

    public function test_multiple_executor_renders_ambiguous_identity_without_guessed_selection(): void
    {
        $this->actionStandard->update([
            'selection_mode' => 'multiple',
            'action_options' => ['OK', 'TAMBAH OLI'],
        ]);
        $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);
        $item = PmExecutionItem::query()->findOrFail($itemId);
        $item->forceFill(['action_options_snapshot' => ['Ok', 'OK', 'TAMBAH OLI']])->save();
        DB::table('pm_execution_item_action_results')->insert([
            'pm_execution_item_id' => $itemId,
            'selected_label_snapshot' => 'OK',
            'selected_label_identity_hash' => hash('sha256', 'OK'),
            'option_position_snapshot' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertOk();
        $body = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*type="checkbox")(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertSame('OK', DB::table('pm_execution_item_action_results')->where('pm_execution_item_id', $itemId)->value('selected_label_snapshot'));
    }

    public function test_multiple_executor_renders_reopened_selected_choices_and_explicit_clear_control(): void
    {
        $this->actionStandard->update([
            'selection_mode' => 'multiple',
            'action_options' => ['OK', 'TAMBAH OLI', 'LUBRIKASI'],
        ]);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => ['OK', 'LUBRIKASI']],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertOk();
        $body = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/<input(?=[^>]*type="checkbox")(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*value="OK")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*type="checkbox")(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*value="LUBRIKASI")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*type="checkbox")(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*value="TAMBAH OLI")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*type="checkbox")(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*value="TAMBAH OLI")[^>]*>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*name="execution_action\['.$itemId.'\]")/', $body);
        $response->assertSee('Kosongkan Pilihan');
        $this->assertMatchesRegularExpression('/<input(?=[^>]*name="execution_clear\['.$itemId.'\]")(?=[^>]*disabled)[^>]*>/', $body);
        $this->assertMatchesRegularExpression('/<span(?=[^>]*peer-focus-visible:ring-4)(?=[^>]*peer-focus-visible:ring-\[#1A73E8\]\/10)[^>]*>/', $body);
    }

    public function test_multiple_executor_renders_inactive_clear_marker_for_unanswered_item(): void
    {
        $this->actionStandard->update([
            'selection_mode' => 'multiple',
            'action_options' => ['OK', 'TAMBAH OLI', 'LUBRIKASI'],
        ]);
        $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertOk();
        $body = (string) $response->getContent();

        // Marker clear tersedia tetapi nonaktif: item segar tanpa hasil tidak boleh terkirim sebagai CLEAR.
        $this->assertMatchesRegularExpression('/<input(?=[^>]*name="execution_clear\['.$itemId.'\]")(?=[^>]*value="1")(?=[^>]*disabled)[^>]*>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*type="hidden")(?=[^>]*name="execution_action\['.$itemId.'\]")/', $body);
    }

    public function test_multiple_legacy_scalar_renders_readable_without_guessed_selection(): void
    {
        $this->actionStandard->update([
            'selection_mode' => 'multiple',
            'action_options' => ['OK', 'TAMBAH OLI', 'LUBRIKASI'],
        ]);
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);
        PmExecutionItem::query()->findOrFail($itemId)->forceFill(['action_value' => 'OK'])->save();

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertOk();
        $response->assertSeeHtml('Hasil tersimpan (mode lama): OK');
        $body = (string) $response->getContent();

        // Scalar legacy tidak boleh menandai pilihan mana pun karena posisi historisnya ambigu.
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*name="execution_action\['.$itemId.'\]\[\]")(?=[^>]*checked)[^>]*>/', $body);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*type="hidden")(?=[^>]*name="execution_action\['.$itemId.'\]")/', $body);
    }

    public function test_single_executor_keeps_scalar_contract_without_multiple_controls(): void
    {
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => 'OK'],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->operator)->get("/pm/executor/{$this->machine->machine_code}");

        $response->assertOk();
        $body = (string) $response->getContent();

        // Mode single mempertahankan hidden scalar dan tidak mendapat kontrol multiple.
        $this->assertMatchesRegularExpression('/<input(?=[^>]*type="hidden")(?=[^>]*name="execution_action\['.$itemId.'\]")(?=[^>]*value="OK")[^>]*>/', $body);
        $this->assertDoesNotMatchRegularExpression('/name="execution_action\['.$itemId.'\]\[\]"/', $body);
        $this->assertDoesNotMatchRegularExpression('/name="execution_clear\['.$itemId.'\]"/', $body);
        $response->assertDontSee('Kosongkan Pilihan');
    }

    public function test_single_array_patch_is_rejected_without_mutation(): void
    {
        $execution = $this->startCanonicalExecution();
        $itemId = $this->itemIdFor($this->actionStandard);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$itemId => ['OK']],
        ])->assertSessionHasErrors();

        $this->assertNull(PmExecutionItem::query()->findOrFail($itemId)->action_value);
        $this->assertSame(0, (int) $execution->fresh()->revision);
    }

    public function test_start_rejects_ambiguous_multiple_legacy_choices_without_execution_or_snapshot(): void
    {
        $this->actionStandard->update([
            'selection_mode' => 'multiple',
            'action_options' => ['OK', ' ok ', 'NG'],
        ]);

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertSessionHasErrors('machine');

        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
        $this->assertSame(0, PmExecutionItem::query()->count());
        $this->assertSame('scheduled', $this->scheduleDate->fresh()->status);
        $this->assertSame(['OK', ' ok ', 'NG'], $this->actionStandard->fresh()->action_options);
    }

    public function test_start_rejects_ambiguous_mapped_choices_before_snapshot_commit(): void
    {
        $ok = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $duplicate = PmAction::query()->create(['action_label' => ' ok ', 'is_active' => true]);
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $this->actionStandard->actions()->attach($ok->id, ['position' => 1]);
        $this->actionStandard->actions()->attach($duplicate->id, ['position' => 2]);

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start")
            ->assertSessionHasErrors(['machine' => 'Mode Pilih Banyak memerlukan pilihan Action dengan label unik.']);

        $this->assertSame(0, PmExecution::query()->count());
        $this->assertSame(0, PmExecutionItem::query()->count());
        $this->assertSame('scheduled', $this->scheduleDate->fresh()->status);
        $this->assertSame(2, $this->actionStandard->fresh()->actions()->count());
    }

    public function test_start_keeps_duplicate_legacy_single_choices_without_rewriting_them(): void
    {
        $this->actionStandard->update([
            'selection_mode' => null,
            'action_options' => ['OK', 'OK', 'NG'],
        ]);

        $execution = app(PmExecutionService::class)->startExecutionOnly(
            $this->s1Request(),
            $this->machine,
            $this->operator,
        );

        $item = PmExecutionItem::query()->where('pm_execution_id', $execution->id)
            ->where('pm_checksheet_standard_id', $this->actionStandard->id)->firstOrFail();
        $this->assertSame('single', $item->effectiveSelectionMode());
        $this->assertSame(['OK', 'OK', 'NG'], $item->action_options_snapshot);
        $this->assertSame(['OK', 'OK', 'NG'], $this->actionStandard->fresh()->action_options);
    }

    public function test_canonical_start_reopens_executor_from_frozen_selection_mode_snapshot(): void
    {
        $this->actionStandard->update(['selection_mode' => 'multiple']);
        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());

        $execution = app(PmExecutionService::class)->startExecutionOnly(
            request: $this->s1Request(),
            machine: $this->machine,
            operator: $this->operator,
        );

        $this->assertModelExists($execution);
        $this->assertNotNull($execution->definition_snapshot_at);
        $item = PmExecutionItem::query()
            ->where('pm_execution_id', $execution->id)
            ->where('pm_checksheet_standard_id', $this->actionStandard->id)
            ->firstOrFail();
        $this->assertSame($execution->id, $item->pm_execution_id);
        $this->assertSame('multiple', $item->selection_mode_snapshot);
        $this->assertSame('multiple', $item->effectiveSelectionMode());

        $this->actionStandard->update(['selection_mode' => 'single']);
        $context = app(PmExecutionService::class)->getExecutorContext($this->machine->fresh());

        $this->assertSame($execution->id, $context['execution']->id);
        $this->assertSame('snapshot', $context['item_key_mode']);
        $this->assertSame((string) $item->id, $context['parts'][0]['items'][0]['key']);
        $this->assertSame('multiple', $context['parts'][0]['items'][0]['selection_mode']);
    }

    public function test_legacy_execution_without_matching_item_keeps_single_mode_after_master_changes(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $this->scheduleDate->update(['status' => 'in_progress']);
        $this->assertNull($execution->definition_snapshot_at);
        $this->assertDatabaseMissing('pm_execution_items', [
            'pm_execution_id' => $execution->id,
            'pm_checksheet_standard_id' => $this->actionStandard->id,
        ]);

        $service = app(PmExecutionService::class);
        $this->assertSame('single', $service->getExecutorContext($this->machine)['parts'][0]['items'][0]['selection_mode']);

        $this->actionStandard->update(['selection_mode' => 'multiple']);

        $context = $service->getExecutorContext($this->machine);
        $this->assertSame($execution->id, $context['execution']->id);
        $this->assertSame('legacy', $context['item_key_mode']);
        $this->assertSame('single', $context['parts'][0]['items'][0]['selection_mode']);
    }

    /**
     * Slice A: legacy row dengan snapshot NULL tetap dapat dibaca tanpa backfill.
     */
    public function test_slice_a_legacy_execution_with_null_snapshots_remains_readable(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $loaded = PmExecution::query()->findOrFail($execution->id);
        $this->assertNull($loaded->machine_code_snapshot);
        $this->assertNull($loaded->checksheet_name_snapshot);
    }

    public function test_legacy_no_snapshot_null_mode_remains_single_after_master_changes(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $this->scheduleDate->update(['status' => 'in_progress']);
        $this->actionStandard->update(['selection_mode' => 'multiple']);

        $context = app(PmExecutionService::class)->getExecutorContext($this->machine);
        $this->assertSame('single', $context['parts'][0]['items'][0]['selection_mode']);

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$this->actionStandard->id => 'OK'],
        ])->assertSessionHasNoErrors();

        $item = PmExecutionItem::query()
            ->where('pm_execution_id', $execution->id)
            ->where('pm_checksheet_standard_id', $this->actionStandard->id)
            ->firstOrFail();
        $this->assertNull($item->selection_mode_snapshot);
        $this->assertSame('OK', $item->action_value);
        $this->assertDatabaseMissing('pm_execution_item_action_results', ['pm_execution_item_id' => $item->id]);
    }

    public function test_legacy_execution_draft_patch_preserves_omitted_scalar_and_range(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $this->scheduleDate->update(['status' => 'in_progress']);
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->revision,
            'execution_action' => [$this->actionStandard->id => 'OK'],
            'execution_number' => [
                $this->numberStandard->id => 60,
                $this->rangeStandard->id => 120,
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/draft", [
            'expected_revision' => (int) $execution->fresh()->revision,
            'execution_number' => [$this->numberStandard->id => 61],
        ])->assertSessionHasNoErrors();

        $this->assertSame('OK', PmExecutionItem::query()->where('pm_checksheet_standard_id', $this->actionStandard->id)->firstOrFail()->action_value);
        $this->assertSame('61.00', PmExecutionItem::query()->where('pm_checksheet_standard_id', $this->numberStandard->id)->firstOrFail()->number_value);
        $this->assertSame('120.00', PmExecutionItem::query()->where('pm_checksheet_standard_id', $this->rangeStandard->id)->firstOrFail()->number_value);
    }

    /**
     * Slice A: lokasi authoritative yang hilang menghentikan creation sebelum insert.
     */
    public function test_slice_a_missing_location_fails_closed_before_new_execution_insert(): void
    {
        $this->machine->location->delete();

        try {
            app(PmExecutionService::class)->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('Lokasi soft-deleted harus menolak execution baru.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('lokasi', mb_strtolower(collect($e->errors())->flatten()->first()));
        }

        $this->assertSame(0, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * Slice A: checksheet trashed (soft-deleted) gagal closed sebelum insert execution baru.
     */
    public function test_slice_a_missing_checksheet_fails_closed_before_new_execution_insert(): void
    {
        $checksheet = $this->scheduleDate->schedule->checksheetMachine->checksheet;
        $checksheet->delete();

        try {
            app(PmExecutionService::class)->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('Checksheet soft-deleted harus menolak execution baru.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('checksheet', mb_strtolower(collect($e->errors())->flatten()->first()));
        }

        $this->assertSame(0, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * Slice A: assignment provenance mismatch (checksheetMachine.machine_id ≠ locked Machine.id)
     * fail closed sebelum insert execution baru.
     */
    public function test_slice_a_assignment_machine_mismatch_fails_closed(): void
    {
        $otherMachine = Machine::query()->create([
            'location_id' => $this->machine->location_id,
            'machine_code' => 'UC-MISMATCH',
            'machine_name' => 'MOTOR Mismatch',
            'qr_token' => 'qr-uc-mismatch',
            'is_active' => true,
        ]);
        $this->scheduleDate->schedule->checksheetMachine->forceFill(['machine_id' => $otherMachine->id])->save();

        try {
            app(PmExecutionService::class)->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('Assignment machine mismatch harus menolak execution baru.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak sesuai', mb_strtolower(collect($e->errors())->flatten()->first()));
        }

        $this->assertSame(0, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * Membuat execution dan dua file media deterministik untuk assertion lifecycle.
     */
    private function createExecutionWithMedia(string $status, array $overrides = []): PmExecution
    {
        $execution = PmExecution::query()->create(array_merge([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => $status,
        ], $overrides));
        PmScheduleDate::query()->whereKey($this->scheduleDate->id)->update([
            'status' => $status,
            'status_changed_at' => now(),
        ]);
        $filePath = "pm-execution-media/{$status}-compressed.jpg";
        $originalFilePath = "pm-execution-media/{$status}-original.jpg";
        Storage::disk('public')->put($filePath, 'compressed-content');
        Storage::disk('public')->put($originalFilePath, 'original-content');

        $execution->media()->create([
            'pm_checksheet_part_id' => $this->part->id,
            'part_name_snapshot' => $this->part->part_name,
            'file_type' => 'photo',
            'file_path' => $filePath,
            'original_file_path' => $originalFilePath,
            'file_name' => "{$status}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 18,
            'original_file_size' => 16,
            'compressed_file_size' => 18,
            'note' => 'Catatan media '.$status,
            'uploaded_by' => $this->operator->id,
            'uploaded_by_name_snapshot' => $this->operator->name,
        ]);

        return $execution;
    }

    /**
     * ID item execution untuk sebuah standard master. Executor memakai
     * pm_execution_items.id sebagai kunci input, bukan id standard yang bisa
     * berubah/hilang setelah Mulai PM.
     */
    private function itemIdFor(PmChecksheetStandard $standard): int
    {
        $itemId = PmExecutionItem::query()
            ->where('pm_checksheet_standard_id', $standard->id)
            ->orderBy('id')
            ->value('id');

        $this->assertNotNull($itemId, "Item execution untuk standard {$standard->standard_name} belum ada.");

        return (int) $itemId;
    }

    /**
     * Membuat exception duplicate-key realistis tanpa menjalankan migration Slice 2.
     */
    private function makeUniqueViolation(string $indexName, string $table): UniqueConstraintViolationException
    {
        $duplicateMessage = "Duplicate entry '1' for key '{$indexName}'";
        $previous = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 {$duplicateMessage}", 23000);
        $previous->errorInfo = ['23000', 1062, $duplicateMessage];

        return new UniqueConstraintViolationException(
            'mysql',
            "insert into `{$table}` (`pm_schedule_date_id`, `machine_id`) values (?, ?)",
            [1, 1],
            $previous,
        );
    }

    /**
     * Seam deterministik untuk mensimulasikan competitor yang belum terlihat
     * pada pembacaan pertama, lalu winner sudah tersedia saat fallback.
     */
    private function collisionService(UniqueConstraintViolationException $violation, bool $hideCanonicalAlways = false): PmExecutionService
    {
        return new class(app(PmWarningService::class), app(PmExecutionHistoryService::class), app(ActivityLogService::class), app(AdminNotificationService::class), $violation, $hideCanonicalAlways) extends PmExecutionService
        {
            public int $canonicalReads = 0;

            public int $insertAttempts = 0;

            public function __construct(
                PmWarningService $warningService,
                PmExecutionHistoryService $historyService,
                ActivityLogService $activityLogService,
                AdminNotificationService $notificationService,
                private UniqueConstraintViolationException $violation,
                private bool $hideCanonicalAlways,
            ) {
                parent::__construct($warningService, $historyService, $activityLogService, $notificationService, app(PmActionService::class), app(LifecyclePolicy::class));
            }

            protected function findCanonicalExecution(Machine $machine, PmScheduleDate $scheduleDate, bool $lock = false): ?PmExecution
            {
                $this->canonicalReads++;

                if ($this->hideCanonicalAlways || $this->canonicalReads === 1) {
                    return null;
                }

                return parent::findCanonicalExecution($machine, $scheduleDate, $lock);
            }

            protected function createExecutionForStart(PmScheduleDate $scheduleDate, Machine $machine, User $operator): PmExecution
            {
                $this->insertAttempts++;

                throw $this->violation;
            }
        };
    }

    /**
     * S1-13: named canonical collision rollback-safe dan kembali ke winner canonical.
     */
    public function test_s1_canonical_unique_collision_recovers_to_existing_execution(): void
    {
        $canonical = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(2),
        ]);
        $service = $this->collisionService($this->makeUniqueViolation('pm_executions_pm_schedule_date_id_unique', 'pm_executions'));

        $execution = $service->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);

        $this->assertSame(1, $service->insertAttempts);
        $this->assertSame($canonical->id, $execution->id);
        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-14: duplicate-key lain langsung melempar object exception asli.
     */
    public function test_s1_unrelated_unique_violation_is_rethrown_without_collision_recovery(): void
    {
        PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(2),
        ]);
        $violation = $this->makeUniqueViolation('users_username_unique', 'users');
        $service = $this->collisionService($violation);

        try {
            $service->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('Unique violation unrelated harus dilempar ulang.');
        } catch (UniqueConstraintViolationException $caught) {
            $this->assertSame($violation, $caught);
        }

        $this->assertSame(1, $service->canonicalReads);
        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-15: canonical collision tanpa winner authoritative tidak disamarkan.
     */
    public function test_s1_unresolvable_canonical_collision_rethrows_original_exception(): void
    {
        $violation = $this->makeUniqueViolation('pm_executions_pm_schedule_date_id_unique', 'pm_executions');
        $service = $this->collisionService($violation, true);

        try {
            $service->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('Collision tanpa canonical harus melempar exception asli.');
        } catch (UniqueConstraintViolationException $caught) {
            $this->assertSame($violation, $caught);
        }

        $this->assertSame(0, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-16: fallback tetap menegakkan lifecycle waiting_review.
     */
    public function test_s1_canonical_collision_on_processed_occurrence_is_rejected_by_lifecycle(): void
    {
        PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'waiting_review',
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(10),
        ]);
        $service = $this->collisionService($this->makeUniqueViolation('pm_executions_pm_schedule_date_id_unique', 'pm_executions'));

        try {
            $service->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
            $this->fail('waiting_review harus tetap ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame('PM untuk occurrence ini sudah diproses dan tidak dapat dimulai ulang.', collect($e->errors())->flatten()->first());
        }

        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S1-17: HTTP upload memakai execution canonical dan part occurrence yang benar.
     */
    public function test_s1_media_upload_route_uses_same_canonical_execution_and_owned_part(): void
    {
        $started = app(PmExecutionService::class)->startExecutionOnly($this->s1Request(), $this->machine, $this->operator);
        $this->actingAs($this->operator)->post("/pm/executor/{$this->machine->machine_code}/media/upload", [
            'part_id' => $this->part->id,
            'part_note' => 'Foto via route produksi',
            'media_file' => UploadedFile::fake()->image('canonical.jpg', 640, 480),
        ])->assertRedirect("/pm/executor/{$this->machine->machine_code}");

        $media = PmExecutionMedia::query()->firstOrFail();
        $this->assertSame($started->id, (int) $media->pm_execution_id);
        $this->assertSame($this->part->id, (int) $media->pm_checksheet_part_id);
        $execution = PmExecution::query()->findOrFail($media->pm_execution_id);
        $execution->loadMissing('scheduleDate.schedule');
        $this->assertSame((int) $execution->scheduleDate->schedule->pm_checksheet_machine_id, (int) $this->part->pm_checksheet_machine_id);
        Storage::disk('public')->assertExists($media->file_path);
    }

    /**
     * S1-18: cross-occurrence part ditolak sebelum persistence file/row.
     */
    public function test_s1_media_upload_route_rejects_cross_occurrence_part_without_file_or_row(): void
    {
        $this->startCanonicalExecution();

        $otherChecksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-ROUTE-OTHER',
            'checksheet_name' => 'Checksheet Lain Route',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $otherChecksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $otherChecksheet->id,
            'machine_id' => $this->machine->id,
            'created_by' => $this->admin->id,
        ]);
        $otherPart = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $otherChecksheetMachine->id,
            'part_name' => 'Part Route Occurrence Lain',
            'is_active' => true,
        ]);
        $filesBefore = Storage::disk('public')->files('pm-execution-media');

        $this->actingAs($this->operator)->postJson("/pm/executor/{$this->machine->machine_code}/media/upload", [
            'part_id' => $otherPart->id,
            'part_note' => 'Part salah occurrence',
            'media_file' => UploadedFile::fake()->image('cross.jpg', 640, 480),
        ])->assertStatus(422)->assertJsonPath('errors.part_id.0', 'Part media tidak sesuai dengan checksheet occurrence PM.');

        $this->assertSame(0, PmExecutionMedia::query()->count());
        $this->assertSame($filesBefore, Storage::disk('public')->files('pm-execution-media'));
    }

    /**
     * S1-19: part_id positif yang tidak ada tidak boleh berubah menjadi unassigned.
     */
    public function test_s1_media_upload_route_rejects_missing_part_instead_of_silently_unassigning(): void
    {
        $this->startCanonicalExecution();

        $missingPartId = (int) PmChecksheetPart::query()->max('id') + 999;
        $filesBefore = Storage::disk('public')->files('pm-execution-media');

        $this->actingAs($this->operator)->postJson("/pm/executor/{$this->machine->machine_code}/media/upload", [
            'part_id' => $missingPartId,
            'part_note' => 'Part tidak ada',
            'media_file' => UploadedFile::fake()->image('missing.jpg', 640, 480),
        ])->assertStatus(422)->assertJsonPath('errors.part_id.0', 'Part media tidak sesuai dengan checksheet occurrence PM.');

        $this->assertSame(0, PmExecutionMedia::query()->count());
        $this->assertSame($filesBefore, Storage::disk('public')->files('pm-execution-media'));
    }

    /**
     * S2-01: migration membuat named unique index exact dan memertahankan
     * ordinary index serta NOT NULL pada pm_schedule_date_id.
     */
    public function test_s2_migration_creates_named_unique_index_and_preserves_existing_constraints(): void
    {
        $indexes = collect(Schema::getIndexes('pm_executions'))->keyBy('name');

        $this->assertTrue($indexes->has('pm_executions_pm_schedule_date_id_unique'));
        $this->assertTrue((bool) $indexes->get('pm_executions_pm_schedule_date_id_unique')['unique']);
        $this->assertTrue($indexes->has('pm_executions_pm_schedule_date_id_index'));
        $this->assertFalse((bool) $indexes->get('pm_executions_pm_schedule_date_id_index')['unique']);

        // Kolom canonical tetap NOT NULL; migration tidak mengubah perilaku nullability.
        $column = collect(Schema::getColumns('pm_executions'))->firstWhere('name', 'pm_schedule_date_id');
        $this->assertNotNull($column);
        $this->assertFalse((bool) $column['nullable']);
    }

    /**
     * S2-02: direct insert database kedua untuk occurrence yang sama ditolak
     * oleh unique index (bypass resolver aplikasi); count akhir tetap satu.
     */
    public function test_s2_second_direct_insert_for_same_occurrence_is_rejected_by_unique_index(): void
    {
        PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ]);

        try {
            DB::table('pm_executions')->insert([
                'pm_schedule_date_id' => $this->scheduleDate->id,
                'machine_id' => $this->machine->id,
                'operator_id' => $this->operator->id,
                'operator_name_snapshot' => $this->operator->name,
                'status' => 'in_progress',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Insert kedua harus ditolak oleh unique index.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertCanonicalUniqueViolation($e);
        }

        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * S2-03: execution soft-deleted tetap menempati unique index, sehingga
     * direct insert pengganti untuk occurrence yang sama ditolak database.
     */
    public function test_s2_soft_deleted_execution_still_occupies_unique_index_and_blocks_direct_insert(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ]);
        $execution->delete(); // soft delete: row tetap ada dan menempati unique index

        try {
            DB::table('pm_executions')->insert([
                'pm_schedule_date_id' => $this->scheduleDate->id,
                'machine_id' => $this->machine->id,
                'operator_id' => $this->operator->id,
                'operator_name_snapshot' => $this->operator->name,
                'status' => 'in_progress',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Insert pengganti harus ditolak karena row soft-deleted tetap menempati unique index.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertCanonicalUniqueViolation($e);
        }

        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());
    }

    /**
     * ActivityLogService membutuhkan request dengan session store untuk menulis
     * audit nyata; dipakai test yang memanggil transition() di luar HTTP test.
     */
    private function bindSessionRequest(): void
    {
        $request = Request::create('/pm/test', 'PATCH');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
    }

    /**
     * TASK-006 Slice C (koreksi B-3): retirement tidak boleh memutus eksekusi
     * canonical yang sudah dimulai; submit tetap mencapai waiting_review dan
     * materialisasi occurrence baru pada era ENDED tetap terhenti.
     */
    public function test_retired_machine_keeps_canonical_in_progress_execution_completable(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $this->scheduleDate->forceFill(['status' => 'in_progress', 'status_changed_at' => now()])->save();
        // Execution buatan tangan ini harus ikut dibekukan definisinya agar
        // jalur executor memakai snapshot, bukan definisi master hidup.
        app(PmExecutionService::class)->captureLegacyDefinitions($execution->id, true);

        $this->bindSessionRequest();
        app(MachineLifecycleService::class)->transition($this->machine, 'retired');

        // Retirement mengakhiri era schedule pada transaksi yang sama.
        $this->assertSame('ended', PmSchedule::query()->findOrFail($this->scheduleDate->pm_schedule_id)->lifecycle_status);

        $this->actingAs($this->operator)
            ->get("/pm/executor/{$this->machine->machine_code}")
            ->assertOk();

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/submit", [
                'expected_revision' => 0,
                'execution_action' => [$this->itemIdFor($this->actionStandard) => 'OK'],
                'execution_number' => [
                    $this->itemIdFor($this->numberStandard) => 60,
                    $this->itemIdFor($this->rangeStandard) => 120,
                ],
            ])
            ->assertRedirect("/machines/{$this->machine->machine_code}");

        $this->assertSame('waiting_review', $execution->fresh()->status);
        $this->assertSame('waiting_review', $this->scheduleDate->fresh()->status);
        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $this->scheduleDate->id)->count());

        // Floor materialisasi: era ENDED tidak boleh menghasilkan occurrence baru.
        $this->assertSame(0, PmScheduleDate::query()
            ->where('pm_schedule_id', $this->scheduleDate->pm_schedule_id)
            ->whereDate('scheduled_date', '>', $this->scheduleDate->scheduled_date->toDateString())
            ->count());
    }

    /**
     * Mesin retired tanpa canonical in_progress tetap ditolak; start tidak
     * menciptakan execution baru.
     */
    public function test_retired_machine_without_continuation_blocks_executor(): void
    {
        $this->bindSessionRequest();
        app(MachineLifecycleService::class)->transition($this->machine, 'retired');

        $this->actingAs($this->operator)
            ->get("/pm/executor/{$this->machine->machine_code}")
            ->assertRedirect("/machines/{$this->machine->machine_code}")
            ->assertSessionHas('flash_error', 'Mesin nonaktif. PM Executor tidak dapat dibuka.');

        $this->actingAs($this->operator)
            ->post("/pm/executor/{$this->machine->machine_code}/start", [
                'expected_revision' => 0,
                'execution_action' => [$this->actionStandard->id => 'OK'],
                'execution_number' => [
                    $this->numberStandard->id => 60,
                    $this->rangeStandard->id => 120,
                ],
            ])
            ->assertRedirect();

        $this->assertSame(0, PmExecution::query()->count());
    }

    /**
     * Execution waiting_review bukan continuation; halaman tetap ditolak.
     */
    public function test_retired_machine_waiting_review_execution_gains_no_continuation(): void
    {
        PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'waiting_review',
            'started_at' => now(),
            'submitted_at' => now(),
        ]);
        $this->scheduleDate->forceFill(['status' => 'waiting_review', 'status_changed_at' => now()])->save();

        $this->bindSessionRequest();
        app(MachineLifecycleService::class)->transition($this->machine, 'retired');

        $this->actingAs($this->operator)
            ->get("/pm/executor/{$this->machine->machine_code}")
            ->assertRedirect("/machines/{$this->machine->machine_code}");
    }

    /**
     * Exception continuation hanya untuk operator: role admin tetap ditolak
     * middleware (403) sebelum mencapai gerbang controller.
     */
    public function test_retired_machine_admin_with_continuation_is_still_blocked(): void
    {
        PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $this->scheduleDate->forceFill(['status' => 'in_progress', 'status_changed_at' => now()])->save();

        $this->bindSessionRequest();
        app(MachineLifecycleService::class)->transition($this->machine, 'retired');

        $this->actingAs($this->admin)
            ->get("/pm/executor/{$this->machine->machine_code}")
            ->assertRedirect('/403');
    }

    public function test_resolve_continuable_execution_returns_exact_canonical_pair(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $this->scheduleDate->forceFill(['status' => 'in_progress', 'status_changed_at' => now()])->save();

        $resolved = app(PmExecutionService::class)->resolveContinuableExecution($this->machine);

        $this->assertNotNull($resolved);
        $this->assertSame($execution->id, $resolved->id);
        $this->assertSame($this->scheduleDate->id, $resolved->pm_schedule_date_id);
        $this->assertSame($this->machine->id, $resolved->machine_id);
    }

    public function test_resolve_continuable_execution_fails_closed_when_earliest_occurrence_has_no_execution(): void
    {
        PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $this->scheduleDate->forceFill(['status' => 'in_progress', 'status_changed_at' => now()])->save();

        // Occurrence in_progress yang lebih awal tanpa execution menjadi kandidat pertama.
        PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->scheduleDate->pm_schedule_id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => now()->subDays(2)->toDateString(),
            'status' => 'in_progress',
        ]);

        $this->assertNull(app(PmExecutionService::class)->resolveContinuableExecution($this->machine));
    }

    public function test_resolve_continuable_execution_fails_closed_on_legacy_duplicate_execution(): void
    {
        // Duplikat legacy hanya mungkin pada state pre-constraint: named unique index
        // diturunkan sementara lalu dipulihkan lewat finally.
        Schema::table('pm_executions', function (Blueprint $table): void {
            $table->dropUnique('pm_executions_pm_schedule_date_id_unique');
        });

        $first = null;
        $second = null;

        try {
            $first = PmExecution::query()->create([
                'pm_schedule_date_id' => $this->scheduleDate->id,
                'machine_id' => $this->machine->id,
                'operator_id' => $this->operator->id,
                'operator_name_snapshot' => $this->operator->name,
                'status' => 'in_progress',
                'started_at' => now()->subHour(),
            ]);
            $first->delete();

            $second = PmExecution::query()->create([
                'pm_schedule_date_id' => $this->scheduleDate->id,
                'machine_id' => $this->machine->id,
                'operator_id' => $this->operator->id,
                'operator_name_snapshot' => $this->operator->name,
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
            $this->scheduleDate->forceFill(['status' => 'in_progress', 'status_changed_at' => now()])->save();

            $this->assertNull(app(PmExecutionService::class)->resolveContinuableExecution($this->machine));
        } finally {
            // Hard delete fixture duplikat, lalu pulihkan named unique index.
            if ($first !== null || $second !== null) {
                PmExecution::withTrashed()
                    ->whereIn('id', array_filter([$first?->id, $second?->id]))
                    ->forceDelete();
            }

            Schema::table('pm_executions', function (Blueprint $table): void {
                $table->unique('pm_schedule_date_id', 'pm_executions_pm_schedule_date_id_unique');
            });
        }
    }

    public function test_resolve_continuable_execution_fails_closed_on_soft_deleted_execution(): void
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $execution->delete();
        $this->scheduleDate->forceFill(['status' => 'in_progress', 'status_changed_at' => now()])->save();

        $this->assertNull(app(PmExecutionService::class)->resolveContinuableExecution($this->machine));
    }

    public function test_resolve_continuable_execution_fails_closed_on_machine_mismatch(): void
    {
        $otherMachine = Machine::query()->create([
            'location_id' => $this->machine->location_id,
            'machine_code' => 'UC-MISMATCH',
            'machine_name' => 'MOTOR Mismatch',
            'qr_token' => 'qr-uc-mismatch',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);

        PmExecution::query()->create([
            'pm_schedule_date_id' => $this->scheduleDate->id,
            'machine_id' => $otherMachine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $this->scheduleDate->forceFill(['status' => 'in_progress', 'status_changed_at' => now()])->save();

        $this->assertNull(app(PmExecutionService::class)->resolveContinuableExecution($this->machine));
    }

    /**
     * S2: validasi pesan unique violation sesuai driver database test.
     * MySQL menyebut nama index; SQLite memakai pesan UNIQUE constraint failed.
     */
    private function assertCanonicalUniqueViolation(UniqueConstraintViolationException $e): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertStringContainsString('pm_executions_pm_schedule_date_id_unique', $e->getMessage());

            return;
        }

        $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
    }
}
