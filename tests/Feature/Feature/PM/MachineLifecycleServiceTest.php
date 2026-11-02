<?php

namespace Tests\Feature\Feature\PM;

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\Auth\ActivityLogService;
use App\Services\PM\InvalidMachineTransitionException;
use App\Services\PM\MachineLifecycleService;
use App\Services\PM\PmReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Menguji seam layanan lifecycle Machine agar semua mutasi state melewati
 * validasi reason, transaksi, audit, dan compatibility projection yang sama.
 */
class MachineLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        // ActivityLogService membutuhkan request dengan session untuk merekam audit nyata.
        $request = Request::create('/pm/test', 'PATCH');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);

        $location = Location::query()->create([
            'location_code' => 'LOC-MLC',
            'location_name' => 'Machine Lifecycle Location',
            'is_active' => true,
        ]);

        $this->machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-MLC',
            'machine_name' => 'Machine Lifecycle Machine',
            'qr_token' => 'qr-mlc',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Kontrak V1: transisi lifecycle Machine tidak memerlukan reason teks
     * pengguna; konfirmasi eksplisit pada UI adalah syarat cukup.
     */
    public function test_transition_succeeds_without_reason(): void
    {
        app(MachineLifecycleService::class)->transition($this->machine, 'inactive');

        $this->assertSame('inactive', $this->machine->fresh()->lifecycle_status);
        $this->assertDatabaseHas('user_activity_logs', [
            'module_name' => 'master_mesin',
            'action' => 'lifecycle_transition',
            'table_name' => 'machines',
            'record_id' => $this->machine->id,
            'description' => null,
        ]);
    }

    /**
     * Aktivasi dari INACTIVE menulis effective-live boundary dan proyeksi lama.
     */
    public function test_inactive_machine_can_be_activated_with_effective_live_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));
        $this->machine->forceFill([
            'lifecycle_status' => 'inactive',
            'is_active' => false,
            'effective_live_from' => '2026-09-01',
        ])->save();

        app(MachineLifecycleService::class)->transition($this->machine, 'active');

        $machine = $this->machine->fresh();
        $this->assertSame('active', $machine->lifecycle_status);
        $this->assertTrue($machine->is_active);
        $this->assertSame('2026-09-15', $machine->effective_live_from?->toDateString());
        $this->assertDatabaseHas('user_activity_logs', [
            'module_name' => 'master_mesin',
            'action' => 'lifecycle_transition',
            'table_name' => 'machines',
            'record_id' => $machine->id,
            'description' => null,
        ]);
    }

    /**
     * BR-PM-LC-002: reactivation ordinary mematerialisasi occurrence sinkron
     * dari boundary effective_live_from, tanpa backfill inactive-gap, dan hanya
     * untuk Schedule era ACTIVE.
     */
    public function test_reactivation_reconciles_active_schedules_from_effective_live_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));
        $this->machine->forceFill([
            'lifecycle_status' => 'inactive',
            'is_active' => false,
            'effective_live_from' => '2026-09-01',
        ])->save();

        [$activeSchedule, $preFloorRow] = $this->seedAssignmentSchedule([
            'lifecycle_status' => 'active',
            'is_active' => true,
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-01',
            'generate_until' => '2026-09-20',
        ]);
        [$pausedSchedule] = $this->seedAssignmentSchedule([
            'lifecycle_status' => 'paused',
            'is_active' => false,
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-01',
            'generate_until' => '2026-09-20',
        ]);
        $preFloorAttributes = $preFloorRow->fresh()->getAttributes();

        app(MachineLifecycleService::class)->transition($this->machine, 'active');
        $machine = $this->machine->fresh();
        $this->assertSame('active', $machine->lifecycle_status);
        $this->assertSame('2026-09-15', $machine->effective_live_from?->toDateString());

        // Hanya recurrence pada/telah boundary yang dibuat; baris sebelum floor
        // identik apa adanya dan tidak ada tanggal inactive-gap.
        $this->assertSame(
            ['2026-08-02', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20'],
            PmScheduleDate::query()
                ->where('pm_schedule_id', $activeSchedule->id)
                ->orderBy('scheduled_date')
                ->pluck('scheduled_date')
                ->map(fn ($date): string => $date->toDateString())
                ->all(),
        );
        $this->assertSame($preFloorAttributes, $preFloorRow->fresh()->getAttributes());

        // Era PAUSED bukan target materialisasi reactivation.
        $this->assertSame(
            ['2026-08-02'],
            PmScheduleDate::query()
                ->where('pm_schedule_id', $pausedSchedule->id)
                ->pluck('scheduled_date')
                ->map(fn ($date): string => $date->toDateString())
                ->all(),
        );
    }

    /**
     * Kegagalan materialisasi wajib me-roll back reactivation: PRIME tidak boleh
     * melaporkan mesin aktif bila materialisasi wajibnya gagal.
     */
    public function test_reactivation_rolls_back_when_materialization_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));
        $this->machine->forceFill([
            'lifecycle_status' => 'inactive',
            'is_active' => false,
            'effective_live_from' => '2026-09-01',
        ])->save();

        $this->seedAssignmentSchedule([
            'lifecycle_status' => 'active',
            'is_active' => true,
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-01',
            'generate_until' => '2026-09-20',
        ]);

        $event = 'eloquent.creating: '.PmScheduleDate::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('Injected materialization failure.');
        });

        try {
            app(MachineLifecycleService::class)->transition($this->machine, 'active');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected materialization failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $machine = $this->machine->fresh();
        $this->assertSame('inactive', $machine->lifecycle_status);
        $this->assertFalse((bool) $machine->is_active);
        $this->assertSame('2026-09-01', $machine->effective_live_from?->toDateString());
        $this->assertDatabaseMissing('user_activity_logs', [
            'module_name' => 'master_mesin',
            'action' => 'lifecycle_transition',
            'record_id' => $this->machine->id,
        ]);
        $this->assertSame(1, PmScheduleDate::query()->count());
    }

    /**
     * RETIRED tidak boleh dihidupkan kembali melalui ordinary lifecycle.
     */
    public function test_retired_machine_cannot_be_activated_by_ordinary_transition(): void
    {
        $this->machine->forceFill([
            'lifecycle_status' => 'retired',
            'is_active' => false,
        ])->save();

        $this->assertThrows(
            fn () => app(MachineLifecycleService::class)->transition($this->machine, 'active'),
            InvalidMachineTransitionException::class,
        );

        $this->assertSame('retired', $this->machine->fresh()->lifecycle_status);
    }

    /**
     * Audit failure wajib membatalkan perubahan lifecycle yang sudah dimulai.
     */
    public function test_audit_failure_rolls_back_machine_transition(): void
    {
        $this->mock(ActivityLogService::class, function ($mock): void {
            $mock->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
        });

        $this->assertThrows(
            fn () => app(MachineLifecycleService::class)->transition($this->machine, 'inactive'),
            RuntimeException::class,
        );

        $this->assertSame('active', $this->machine->fresh()->lifecycle_status);
        $this->assertTrue($this->machine->fresh()->is_active);
    }

    /**
     * Retirement mengakhiri ACTIVE tanpa menulis ulang bukti Schedule historis.
     */
    public function test_retirement_ends_active_schedule(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-MLC',
            'checksheet_name' => 'Machine Lifecycle Checksheet',
            'is_active' => true,
        ]);
        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
        ]);
        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'frequency_type' => 'daily',
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-01',
            'generate_until' => '2026-10-01',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);

        app(MachineLifecycleService::class)->transition($this->machine, 'retired');

        $this->assertSame('retired', $this->machine->fresh()->lifecycle_status);
        $this->assertSame('ended', $schedule->fresh()->lifecycle_status);
    }

    /**
     * TASK-006 Slice C (Correction 10): INACTIVE -> RETIRED harus sah dan
     * mengakhiri Schedule PAUSED melalui otoritas ScheduleLifecycleService,
     * bukan lewat penulisan status langsung.
     */
    public function test_inactive_machine_can_be_retired_ending_paused_schedule_via_authority(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));

        $this->machine->forceFill([
            'lifecycle_status' => 'inactive',
            'is_active' => false,
        ])->save();

        [$schedule] = $this->seedAssignmentSchedule([
            'lifecycle_status' => 'paused',
            'is_active' => false,
            'effective_live_from' => '2026-09-05',
        ]);

        app(MachineLifecycleService::class)->transition($this->machine, 'retired');

        $this->assertSame('retired', $this->machine->fresh()->lifecycle_status);
        $this->assertFalse($this->machine->fresh()->is_active);

        $ended = $schedule->fresh();
        $this->assertSame('ended', $ended->lifecycle_status);
        $this->assertFalse((bool) $ended->is_active);
        $this->assertSame('2026-09-05', $ended->effective_live_from?->toDateString());

        // Penulisan status harus melewati otoritas transisi Schedule (audit pm_schedule).
        $this->assertDatabaseHas('user_activity_logs', [
            'module_name' => 'pm_schedule',
            'action' => 'lifecycle_transition',
            'table_name' => 'pm_schedules',
            'record_id' => $schedule->id,
            'description' => null,
        ]);
        $this->assertSame('ended', $schedule->fresh()->lifecycle_status);
    }

    /**
     * TASK-006 Slice C (Correction 10): Schedule yang sudah ENDED tidak boleh
     * ditulis ulang oleh retirement, dan protected occurrence beserta execution
     * historisnya wajib tetap identik.
     */
    public function test_retirement_does_not_rewrite_ended_schedule_or_protected_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));

        $this->machine->forceFill([
            'lifecycle_status' => 'inactive',
            'is_active' => false,
        ])->save();

        [$schedule, $scheduleDate] = $this->seedAssignmentSchedule([
            'lifecycle_status' => 'ended',
            'is_active' => false,
            'effective_live_from' => '2026-08-01',
            'operational_from' => '2026-08-01',
            'start_date' => '2026-08-01',
            'generate_until' => '2026-08-05',
        ]);

        // Occurrence protected + execution historis approved.
        $scheduleDate->forceFill([
            'status' => 'approved',
            'status_changed_at' => '2026-08-02 08:00:00',
        ])->save();
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDate->id,
            'machine_id' => $this->machine->id,
            'status' => 'approved',
            'started_at' => '2026-08-02 07:00:00',
            'submitted_at' => '2026-08-02 07:30:00',
            'approved_at' => '2026-08-02 08:00:00',
        ]);

        $scheduleBefore = $schedule->fresh()->getAttributes();
        $dateBefore = $scheduleDate->fresh()->getAttributes();
        $executionBefore = $execution->fresh()->getAttributes();

        app(MachineLifecycleService::class)->transition($this->machine, 'retired');

        $this->assertSame('retired', $this->machine->fresh()->lifecycle_status);
        $this->assertSame($scheduleBefore, $schedule->fresh()->getAttributes(), 'Schedule ENDED tidak boleh ditulis ulang.');
        $this->assertSame($dateBefore, $scheduleDate->fresh()->getAttributes(), 'Occurrence protected tidak boleh ditulis ulang.');
        $this->assertSame($executionBefore, $execution->fresh()->getAttributes(), 'Execution historis tidak boleh ditulis ulang.');
    }

    /**
     * TASK-006 Slice C (Correction 10): execution canonical yang sudah dimulai
     * tetap dapat diselesaikan setelah Machine dipensiunkan (kontrak accepted:
     * in_progress -> waiting_review -> approved, bukan overdue/missed).
     */
    public function test_retirement_keeps_started_execution_completable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));

        // Execution dimulai saat Machine masih ACTIVE.
        [$schedule, $scheduleDate] = $this->seedAssignmentSchedule([
            'lifecycle_status' => 'active',
            'is_active' => true,
            'effective_live_from' => '2026-09-01',
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-01',
            'generate_until' => '2026-09-05',
        ]);
        $scheduleDate->forceFill(['status' => 'in_progress'])->save();
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDate->id,
            'machine_id' => $this->machine->id,
            'status' => 'in_progress',
            'started_at' => '2026-09-13 07:00:00',
        ]);

        // Retire di tengah pengerjaan.
        app(MachineLifecycleService::class)->transition($this->machine, 'retired');
        $this->assertSame('retired', $this->machine->fresh()->lifecycle_status);
        $this->assertSame('in_progress', $execution->fresh()->status, 'Execution yang sudah dimulai tidak boleh diubah retirement.');
        $this->assertSame('ended', $schedule->fresh()->lifecycle_status);

        // Submit + approve tetap berjalan: bukan overdue/missed.
        $execution->forceFill(['status' => 'waiting_review', 'submitted_at' => '2026-09-14 08:00:00'])->save();
        $scheduleDate->forceFill(['status' => 'waiting_review', 'status_changed_at' => '2026-09-14 08:00:00'])->save();

        $admin = User::query()->create([
            'name' => 'Admin Retirement',
            'username' => 'admin.retirement',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        app(PmReviewService::class)->approve(request(), $execution->fresh(), $admin, 'Disetujui setelah mesin pensiun', (int) $execution->fresh()->revision);

        $this->assertSame('approved', $execution->fresh()->status);
        $this->assertSame('approved', $scheduleDate->fresh()->status);
        $this->assertNotNull($execution->fresh()->approved_at);
    }

    /**
     * Buat checksheet + assignment + schedule (dan satu occurrence) untuk
     * Machine pada test ini.
     *
     * @param  array<string, mixed>  $scheduleOverrides
     * @return array{0: PmSchedule, 1: PmScheduleDate}
     */
    private function seedAssignmentSchedule(array $scheduleOverrides = []): array
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-C10-'.uniqid(),
            'checksheet_name' => 'Retirement Coverage Checksheet',
            'is_active' => true,
        ]);
        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
        ]);
        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'frequency_type' => 'daily',
            'operational_from' => '2026-08-01',
            'start_date' => '2026-08-01',
            'generate_until' => '2026-08-05',
            'is_active' => false,
            'lifecycle_status' => 'ended',
            ...$scheduleOverrides,
        ]);
        $scheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-08-02',
            'status' => 'scheduled',
            'generated_at' => '2026-08-01 00:00:00',
        ]);

        return [$schedule, $scheduleDate];
    }
}
