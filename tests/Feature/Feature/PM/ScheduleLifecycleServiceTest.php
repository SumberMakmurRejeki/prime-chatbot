<?php

namespace Tests\Feature\Feature\PM;

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetPart;
use App\Models\PmChecksheetStandard;
use App\Models\PmExecution;
use App\Models\PmExecutionHistory;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\Auth\ActivityLogService;
use App\Services\Calendar\CalendarEventService;
use App\Services\Dashboard\DashboardMonitoringService;
use App\Services\Master\PmScheduleUpdateService;
use App\Services\PM\InvalidScheduleTransitionException;
use App\Services\PM\MachineLandingService;
use App\Services\PM\PmExecutionService;
use App\Services\PM\PmReviewService;
use App\Services\PM\PmScheduleDateReconciler;
use App\Services\PM\ScheduleLifecycleService;
use App\Services\PM\StalePreviewException;
use App\Services\Report\ReportPmParentQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Menguji ScheduleLifecycleService sebagai satu-satunya seam mutasi lifecycle Schedule.
 *
 * Test ini hanya memakai database test terisolasi (RefreshDatabase).
 */
class ScheduleLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        // ActivityLogService leest de sessie uit de actieve request; bind een
        // request met session zodat audittests dezelfde echte seam gebruiken.
        $request = Request::create('/pm/test', 'POST');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);

        $location = Location::query()->create([
            'location_code' => 'LOC-SLC',
            'location_name' => 'Schedule Lifecycle Location',
            'is_active' => true,
        ]);

        $this->machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-SLC',
            'machine_name' => 'Schedule Lifecycle Machine',
            'qr_token' => 'qr-slc',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);

        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-SLC',
            'checksheet_name' => 'Schedule Lifecycle Checksheet',
            'is_active' => true,
        ]);

        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
        ]);

        $this->schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'frequency_type' => 'daily',
            'operational_from' => '2026-09-01',
            'start_date' => '2026-09-01',
            'generate_until' => '2026-10-01',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): ScheduleLifecycleService
    {
        return app(ScheduleLifecycleService::class);
    }

    public function test_legacy_paused_schedule_without_structured_provenance_cannot_resume(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Jakarta'));
        $this->schedule->forceFill([
            'lifecycle_status' => 'paused',
            'is_active' => false,
            'effective_live_from' => '2026-11-10',
        ])->save();
        $before = $this->schedule->fresh()->getAttributes();

        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule, 'active', 'Resume legacy'),
            InvalidScheduleTransitionException::class,
        );

        $this->assertSame($before, $this->schedule->fresh()->getAttributes());
        $this->assertSame(0, DB::table('user_activity_logs')->where('record_id', $this->schedule->id)->count());
    }

    public function test_pre_pause_overdue_ages_only_active_days_after_resume(): void
    {
        $this->schedule->update([
            'operational_from' => '2026-11-01',
            'start_date' => '2026-11-01',
            'generate_until' => '2026-11-30',
        ]);
        $due = PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-11-09',
            'status' => 'overdue',
        ]);
        $gap = PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-11-12',
            'status' => 'scheduled',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'paused', 'Pause');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'active', 'Resume');

        $this->artisan('pm:sync-schedule-status')->assertSuccessful();

        $this->assertSame('overdue', $due->fresh()->status);
        $this->assertSame('scheduled', $gap->fresh()->status);
        $this->assertSame('2026-11-09', $due->fresh()->scheduled_date->toDateString());
        Carbon::setTestNow(Carbon::parse('2026-11-21 09:00:00', 'Asia/Jakarta'));
        $this->artisan('pm:sync-schedule-status')->assertSuccessful();
        $this->assertSame('missed', $due->fresh()->status);
        $this->assertSame('scheduled', $gap->fresh()->status);
    }

    private function temporalDate(string $date, string $status = 'scheduled'): PmScheduleDate
    {
        return PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => $date,
            'status' => $status,
        ]);
    }

    public function test_resume_excludes_multiple_pregenerated_gap_dates_but_keeps_pre_pause_start_actionable(): void
    {
        $part = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $this->schedule->pm_checksheet_machine_id,
            'part_name' => 'Temporal Part', 'is_active' => true,
        ]);
        PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $part->id, 'standard_name' => 'Temporal Check',
            'input_type' => 'action', 'action_options' => ['OK'], 'is_required' => true, 'is_active' => true,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-09 09:00:00', 'Asia/Jakarta'));
        $due = $this->temporalDate('2026-09-09', 'overdue');
        $gapDates = collect(['2026-09-10', '2026-09-12', '2026-09-19'])
            ->map(fn (string $date): PmScheduleDate => $this->temporalDate($date));
        $resume = $this->temporalDate('2026-09-20');
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'paused', 'Pause');
        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'active', 'Resume');

        app(PmScheduleDateReconciler::class)->reconcile($this->schedule);
        app(PmScheduleDateReconciler::class)->reconcile($this->schedule);
        $executor = app(PmExecutionService::class);
        $this->assertSame($due->id, $executor->findScheduleDateForExecutor($this->machine->fresh())->id);
        $operator = User::query()->create([
            'name' => 'Temporal Operator', 'username' => 'temporal.operator',
            'password' => 'password', 'role' => 'operator', 'is_active' => true,
        ]);
        $execution = $executor->startExecutionOnly(app('request'), $this->machine->fresh(), $operator);
        $this->assertSame($due->id, $execution->pm_schedule_date_id);
        $this->assertSame($execution->id, $executor->startExecutionOnly(app('request'), $this->machine->fresh(), $operator)->id);
        foreach ($gapDates as $gap) {
            $this->assertSame('scheduled', $gap->fresh()->status);
        }
        $this->assertSame(0, PmExecution::withTrashed()->whereIn('pm_schedule_date_id', $gapDates->pluck('id'))->count());
        $this->assertSame(1, PmScheduleDate::withTrashed()->whereKey($resume->id)->count());
        $this->assertSame(
            [$due->id, $resume->id],
            PmScheduleDate::query()->withoutPauseGap()->whereDate('scheduled_date', '<=', '2026-09-20')->orderBy('scheduled_date')->pluck('id')->all(),
        );
    }

    public function test_same_day_pause_resume_is_empty_and_invalid_repeats_do_not_write(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));
        $today = $this->temporalDate('2026-09-15');
        $executor = app(PmExecutionService::class);
        $this->service()->transition($this->schedule, 'paused', 'Pause');
        $this->assertThrows(
            fn () => $executor->findScheduleDateForExecutor($this->machine->fresh()),
            ValidationException::class,
        );
        $this->assertThrows(fn () => $this->service()->transition($this->schedule, 'paused'), InvalidScheduleTransitionException::class);
        $this->service()->transition($this->schedule, 'active', 'Resume');
        $this->assertSame($today->id, $executor->findScheduleDateForExecutor($this->machine->fresh())->id);
        $this->assertThrows(fn () => $this->service()->transition($this->schedule, 'active'), InvalidScheduleTransitionException::class);
        $this->assertDatabaseCount('pm_schedule_pause_intervals', 1);
        $this->assertSame(2, DB::table('user_activity_logs')->where('record_id', $this->schedule->id)->count());
        $this->assertSame(0, $this->schedule->fresh()->activeAge('2026-09-15', '2026-09-15'));
        $this->assertSame(1, $this->schedule->fresh()->activeAge('2026-09-15', '2026-09-16'));
    }

    public function test_pause_and_resume_audit_failure_roll_back_interval_and_lifecycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'paused', 'Pause');
        $intervalBefore = DB::table('pm_schedule_pause_intervals')->first();
        $this->mock(ActivityLogService::class, function ($mock): void {
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit unavailable'));
        });
        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00', 'Asia/Jakarta'));

        $this->assertThrows(fn () => $this->service()->transition($this->schedule, 'active', 'Resume'), \RuntimeException::class);

        $this->assertSame('paused', $this->schedule->fresh()->lifecycle_status);
        $this->assertEquals($intervalBefore, DB::table('pm_schedule_pause_intervals')->first());
        $this->assertSame(1, DB::table('user_activity_logs')->where('record_id', $this->schedule->id)->count());
    }

    public function test_multiple_cycles_union_aging_and_jakarta_midnight_are_consistent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 17:00:00', 'UTC'));
        $this->service()->transition($this->schedule, 'paused');
        Carbon::setTestNow(Carbon::parse('2026-09-19 17:00:00', 'UTC'));
        $this->service()->transition($this->schedule, 'active');
        $this->service()->transition($this->schedule, 'paused');
        $this->service()->transition($this->schedule, 'active');
        Carbon::setTestNow(Carbon::parse('2026-09-20 17:00:00', 'UTC'));
        $this->service()->transition($this->schedule, 'paused');
        Carbon::setTestNow(Carbon::parse('2026-09-24 17:00:00', 'UTC'));
        $this->service()->transition($this->schedule, 'active');

        $schedule = $this->schedule->fresh();
        $this->assertSame(2, $schedule->activeAge('2026-09-09', '2026-09-25'));
        $this->assertTrue($schedule->isPausedOn('2026-09-10'));
        $this->assertFalse($schedule->isPausedOn('2026-09-20'));
        $this->assertFalse($schedule->isPausedOn('2026-09-25'));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertDatabaseHas('pm_schedule_pause_intervals', ['paused_on' => '2026-09-10 00:00:00', 'resumed_on' => '2026-09-20 00:00:00']);
    }

    public function test_current_projections_exclude_gap_obligations_and_preserve_protected_and_explicit_history(): void
    {
        $this->schedule->update([
            'operational_from' => '2026-11-01', 'start_date' => '2026-11-01', 'generate_until' => '2026-11-20',
        ]);
        $pre = $this->temporalDate('2026-11-09', 'overdue');
        $this->temporalDate('2026-11-10');
        $this->temporalDate('2026-11-11', 'overdue');
        $this->temporalDate('2026-11-12', 'missed');
        $progress = $this->temporalDate('2026-11-13', 'in_progress');
        $review = $this->temporalDate('2026-11-14', 'waiting_review');
        $approved = $this->temporalDate('2026-11-15', 'approved');
        $canonical = $this->temporalDate('2026-11-16');
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $canonical->id, 'machine_id' => $this->machine->id,
            'status' => 'in_progress', 'started_at' => '2026-11-16 09:00:00',
        ]);
        $execution->delete();
        $resume = $this->temporalDate('2026-11-20');
        $before = DB::table('pm_schedule_dates')->orderBy('id')->get()->toJson();
        $executionBefore = $execution->fresh()->getAttributes();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'paused');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'active');
        app(PmScheduleDateReconciler::class)->reconcile($this->schedule);
        $this->artisan('pm:sync-schedule-status')->assertSuccessful();

        $this->assertSame($before, DB::table('pm_schedule_dates')->orderBy('id')->get()->toJson());
        $this->assertSame($executionBefore, $execution->fresh()->getAttributes());
        $expected = [$pre->id, $progress->id, $review->id, $approved->id, $canonical->id, $resume->id];
        $calendar = app(CalendarEventService::class)->events('operator', [
            'start' => '2026-11-01', 'end' => '2026-12-01', 'event_type' => 'pm',
        ]);
        $this->assertEqualsCanonicalizing(array_map(fn (int $id): string => 'pm-'.$id, $expected), array_column($calendar, 'id'));
        $dashboard = app(DashboardMonitoringService::class)->payload(
            Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'),
        );
        $this->assertNull($dashboard['loadErrorMessage']);
        $this->assertSame(['completed' => 2, 'overdue' => 1, 'total' => 3], $dashboard['pmStatus']);
        $this->assertSame(33.3, $dashboard['pmRateSeries']->first()['actual_percentage']);
        $report = app(ReportPmParentQuery::class);
        $this->assertEqualsCanonicalizing($expected, $report->build([])->pluck('schedule_date_id')->all());
        $this->assertCount(9, $report->build(['start_date' => '2026-11-01', 'end_date' => '2026-11-30'])->get());
        $landing = app(MachineLandingService::class)->build($this->machine);
        $this->assertSame($pre->id, $landing['nextPm']->id);
        $this->assertTrue($landing['hasActivePmSchedule']);
    }

    public function test_same_day_pause_resume_invalidates_preview_without_rewriting_schedule_configuration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));
        $checksheet = $this->schedule->checksheetMachine->checksheet;
        $service = app(PmScheduleUpdateService::class);
        $payload = ['frequency_type' => 'daily', 'operational_from' => '2026-09-01'];
        $preview = $service->preview($checksheet, $payload);
        $before = $this->schedule->fresh()->getAttributes();
        unset($before['updated_at']);
        $this->service()->transition($this->schedule, 'paused');
        $this->service()->transition($this->schedule, 'active');

        $this->assertThrows(
            fn () => $service->apply($checksheet, $payload, $preview['token']),
            StalePreviewException::class,
        );

        $after = $this->schedule->fresh()->getAttributes();
        unset($after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('pm_schedule_dates', 0);
        $fresh = $service->preview($checksheet, $payload);
        $this->assertNotSame($preview['token'], $fresh['token']);
        $this->assertSame('applied', $service->apply($checksheet, $payload, $fresh['token'])['status']);
    }

    public function test_resume_preserves_weekly_monthly_end_of_month_and_expired_horizons(): void
    {
        $cases = [
            ['weekly', [5], null, '2026-10-30', '2026-11-20', '2026-11-30', ['2026-11-20', '2026-11-27']],
            ['monthly', null, 31, '2026-01-31', '2026-02-20', '2026-03-31', ['2026-02-28', '2026-03-31']],
            ['monthly', null, 10, '2026-11-01', '2026-11-20', '2026-12-31', ['2026-12-10']],
            ['daily', null, null, '2026-11-01', '2026-11-20', '2026-11-15', []],
        ];
        foreach ($cases as [$frequency, $days, $day, $pause, $resume, $until, $expected]) {
            PmScheduleDate::query()->forceDelete();
            $this->schedule->pauseIntervals()->delete();
            $this->schedule->update([
                'frequency_type' => $frequency, 'weekly_days' => $days, 'monthly_day' => $day,
                'operational_from' => $pause, 'start_date' => $pause, 'generate_until' => $until,
            ]);
            Carbon::setTestNow(Carbon::parse($pause.' 09:00:00', 'Asia/Jakarta'));
            $this->service()->transition($this->schedule, 'paused');
            Carbon::setTestNow(Carbon::parse($resume.' 09:00:00', 'Asia/Jakarta'));
            $this->service()->transition($this->schedule, 'active');

            $reconciler = app(PmScheduleDateReconciler::class);
            $reconciler->reconcile($this->schedule);
            $reconciler->reconcile($this->schedule);

            $this->assertSame($expected, PmScheduleDate::query()->orderBy('scheduled_date')->get()
                ->map(fn (PmScheduleDate $row): string => $row->scheduled_date->toDateString())->all());
            $this->assertSame($frequency, $this->schedule->fresh()->frequency_type);
            $this->assertSame($until, $this->schedule->fresh()->generate_until->toDateString());
        }
    }

    public function test_cron_uses_jakarta_day_at_utc_midnight_boundary(): void
    {
        $date = $this->temporalDate('2026-09-09');
        Carbon::setTestNow(Carbon::parse('2026-09-09 17:00:00', 'UTC'));

        $this->artisan('pm:sync-schedule-status')->assertSuccessful();

        $this->assertSame('overdue', $date->fresh()->status);
        $this->assertDatabaseHas('notifications', ['notification_type' => 'pm_overdue', 'related_id' => $date->id]);
        $this->artisan('pm:sync-schedule-status')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications')->where('related_id', $date->id)->count());
    }

    public function test_gap_soft_deleted_occurrence_is_not_restored_and_protected_execution_remains_continuable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Jakarta'));
        $gap = $this->temporalDate('2026-09-12');
        $gap->delete();
        $progress = $this->temporalDate('2026-09-13', 'in_progress');
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $progress->id, 'machine_id' => $this->machine->id,
            'status' => 'in_progress', 'started_at' => '2026-09-13 09:00:00',
        ]);
        $this->service()->transition($this->schedule, 'paused');
        $this->assertSame($progress->id, app(PmExecutionService::class)->findScheduleDateForExecutor($this->machine->fresh())->id);
        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'active');

        app(PmScheduleDateReconciler::class)->reconcile($this->schedule);

        $this->assertSoftDeleted($gap);
        $this->assertSame('in_progress', $execution->fresh()->status);
        $this->assertSame($progress->id, app(PmExecutionService::class)->findScheduleDateForExecutor($this->machine->fresh())->id);
        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $progress->id)->count());
    }

    /**
     * Reason blank wajib ditolak sebelum transaksi dan mutasi.
     */
    public function test_blank_reason_is_rejected_fail_closed(): void
    {
        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule, 'paused', " \t\n"),
            InvalidScheduleTransitionException::class,
        );
        $this->assertSame('active', $this->schedule->fresh()->lifecycle_status);
        $this->assertSame(0, DB::table('user_activity_logs')->where('record_id', $this->schedule->id)->count());
    }

    /**
     * Kegagalan ActivityLogService harus mengembalikan mutasi lifecycle.
     */
    public function test_audit_failure_rolls_back_lifecycle_transition(): void
    {
        $this->mock(ActivityLogService::class, function ($mock): void {
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit unavailable'));
        });

        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule, 'paused', 'Pause dengan audit gagal'),
            \RuntimeException::class,
        );
        $this->assertSame('active', $this->schedule->fresh()->lifecycle_status);
        $this->assertSame(1, DB::table('pm_schedules')->where('id', $this->schedule->id)->where('is_active', true)->count());
        $this->assertSame(0, DB::table('user_activity_logs')->where('record_id', $this->schedule->id)->count());
    }

    /**
     * RED Slice-B Correction 1: reason wajib dan audit ditulis dalam transaksi yang sama
     * dengan mutasi lifecycle, memuat actor, old/new state, dan alasan.
     */
    public function test_transition_requires_reason_and_writes_atomic_audit(): void
    {
        $this->service()->transition($this->schedule, 'paused', 'Operator menunda jadwal untuk inspeksi');

        $this->assertDatabaseHas('user_activity_logs', [
            'module_name' => 'pm_schedule',
            'action' => 'lifecycle_transition',
            'table_name' => 'pm_schedules',
            'record_id' => $this->schedule->id,
            'description' => 'Operator menunda jadwal untuk inspeksi',
        ]);

        $audit = DB::table('user_activity_logs')
            ->where('table_name', 'pm_schedules')
            ->where('record_id', $this->schedule->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('active', json_decode((string) $audit->old_values, true)['lifecycle_status']);
        $this->assertSame('paused', json_decode((string) $audit->new_values, true)['lifecycle_status']);
        $this->assertFalse((bool) json_decode((string) $audit->new_values, true)['is_active']);
    }

    /**
     * RED Slice-B Correction 3: provenance stale/mismatch ditolak fail-closed.
     */
    public function test_stale_schedule_provenance_cannot_transition_under_wrong_machine_lock(): void
    {
        $otherMachine = Machine::query()->create([
            'location_id' => $this->machine->location_id,
            'machine_code' => 'MC-SLC-OTHER',
            'machine_name' => 'Other Machine',
            'qr_token' => 'qr-slc-other',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);

        $otherChecksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-SLC-OTHER',
            'checksheet_name' => 'Other Checksheet',
            'is_active' => true,
        ]);

        $otherAssignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $otherChecksheet->id,
            'machine_id' => $otherMachine->id,
        ]);

        // Simulasi caller basi: setelah caller membaca snapshot schedule yang
        // masih menunjuk assignment mesin pertama, baris schedule dipindah ke
        // assignment mesin lain. Transisi harus ditolak sebelum mutasi.
        PmSchedule::query()->whereKey($this->schedule->id)->update([
            'pm_checksheet_machine_id' => $otherAssignment->id,
        ]);

        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule, 'paused', 'Stale provenance test'),
            InvalidScheduleTransitionException::class,
        );
        $this->assertSame('active', $this->schedule->fresh()->lifecycle_status);
    }

    /**
     * Semua transisi legal mengubah lifecycle_status dan proyeksi is_active
     * pada operasi persist yang sama.
     */
    public function test_legal_schedule_transitions_update_lifecycle_and_compatibility_projection(): void
    {
        $this->service()->transition($this->schedule, 'paused', 'Pause untuk maintenance');
        $this->assertDatabaseHas('pm_schedules', [
            'id' => $this->schedule->id,
            'lifecycle_status' => 'paused',
            'is_active' => 0,
        ]);

        $this->service()->transition($this->schedule->fresh(), 'active', 'Resume setelah maintenance');
        $this->assertDatabaseHas('pm_schedules', [
            'id' => $this->schedule->id,
            'lifecycle_status' => 'active',
            'is_active' => 1,
        ]);

        $this->service()->transition($this->schedule->fresh(), 'ended', 'Era jadwal selesai');
        $this->assertDatabaseHas('pm_schedules', [
            'id' => $this->schedule->id,
            'lifecycle_status' => 'ended',
            'is_active' => 0,
        ]);
    }

    /**
     * Paused dapat langsung diakhiri tanpa harus kembali aktif lebih dulu.
     */
    public function test_paused_schedule_can_be_ended_directly(): void
    {
        $this->service()->transition($this->schedule, 'paused', 'Pause sebelum berakhir');
        $this->service()->transition($this->schedule->fresh(), 'ended', 'Era jadwal selesai');

        $this->assertDatabaseHas('pm_schedules', [
            'id' => $this->schedule->id,
            'lifecycle_status' => 'ended',
            'is_active' => 0,
        ]);
    }

    public function test_resume_records_interval_without_rewriting_operational_boundaries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Jakarta'));
        $this->service()->transition($this->schedule, 'paused', 'Pause');
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00', 'Asia/Jakarta'));

        $this->service()->transition($this->schedule->fresh(), 'active', 'Resume setelah maintenance');

        $schedule = $this->schedule->fresh();
        $this->assertSame('2026-09-01', $schedule->operational_from?->toDateString());
        $this->assertSame('2026-09-01', $schedule->start_date?->toDateString());
        $this->assertSame('2026-10-01', $schedule->generate_until?->toDateString());
        $this->assertNull($schedule->effective_live_from);
        $this->assertDatabaseHas('pm_schedule_pause_intervals', [
            'pm_schedule_id' => $schedule->id,
            'paused_on' => '2026-09-10 00:00:00',
            'resumed_on' => '2026-09-15 00:00:00',
        ]);
        $this->assertTrue($schedule->is_active);
        $this->assertSame(0, PmScheduleDate::query()->where('pm_schedule_id', $schedule->id)->count());
    }

    /**
     * Pause tidak menggeser floor operasional.
     */
    public function test_pause_does_not_write_effective_live_from(): void
    {
        $this->service()->transition($this->schedule, 'paused', 'Pause sementara');

        $this->assertNull($this->schedule->fresh()->effective_live_from);
    }

    /**
     * ENDED bersifat terminal: resume ordinary ditolak dan state tersimpan tidak berubah.
     */
    public function test_ended_schedule_cannot_be_resumed(): void
    {
        $this->service()->transition($this->schedule, 'ended', 'Era jadwal selesai');

        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule->fresh(), 'active', 'Coba hidupkan kembali'),
            InvalidScheduleTransitionException::class,
        );

        $schedule = $this->schedule->fresh();
        $this->assertSame('ended', $schedule->lifecycle_status);
        $this->assertFalse($schedule->is_active);
        $this->assertNull($schedule->effective_live_from);
    }

    /**
     * Transisi berulang dan target/state tidak dikenal ditolak secara deterministik.
     */
    public function test_repeated_and_unknown_transitions_fail_deterministically(): void
    {
        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule, 'active', 'Sudah aktif'),
            InvalidScheduleTransitionException::class,
        );

        $this->service()->transition($this->schedule, 'paused', 'Pause sementara');
        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule->fresh(), 'paused', 'Pause dua kali'),
            InvalidScheduleTransitionException::class,
        );

        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule->fresh(), 'retired', 'State tidak dikenal'),
            InvalidScheduleTransitionException::class,
        );

        $this->assertSame('paused', $this->schedule->fresh()->lifecycle_status);
    }

    /**
     * State terminal dipertahankan meski boolean legacy menyatakan aktif;
     * boolean tidak boleh menghidupkan kembali schedule ENDED.
     */
    public function test_ended_schedule_is_not_revived_by_legacy_boolean_state(): void
    {
        $this->schedule->forceFill([
            'lifecycle_status' => 'ended',
            'is_active' => true,
        ])->save();

        $this->assertThrows(
            fn () => $this->service()->transition($this->schedule->fresh(), 'active', 'Coba hidupkan kembali'),
            InvalidScheduleTransitionException::class,
        );

        $schedule = $this->schedule->fresh();
        $this->assertSame('ended', $schedule->lifecycle_status);
        $this->assertTrue($schedule->is_active);
    }

    /**
     * Execution canonical yang sudah dimulai tetap dapat menyelesaikan review
     * meski Schedule sudah PAUSED, dan kondisinya tidak ditulis ulang.
     */
    public function test_started_execution_and_protected_history_are_preserved(): void
    {
        $date = PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-09-12',
            'status' => 'in_progress',
        ]);
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $date->id,
            'machine_id' => $this->machine->id,
            'status' => 'in_progress',
            'started_at' => '2026-09-12 08:00:00',
        ]);
        PmExecutionHistory::query()->create([
            'pm_execution_id' => $execution->id,
            'field_name' => 'status',
            'old_value' => null,
            'new_value' => 'in_progress',
            'change_note' => 'fixture history',
        ]);

        // Snapshot histori sebelum transisi lifecycle apa pun.
        $executionBefore = DB::table('pm_executions')->where('id', $execution->id)->first();
        $historyBefore = DB::table('pm_execution_history')->where('pm_execution_id', $execution->id)->orderBy('id')->get()->toArray();
        $dateBefore = DB::table('pm_schedule_dates')->where('id', $date->id)->first();

        $this->service()->transition($this->schedule, 'paused', 'Pause sementara');
        $this->service()->transition($this->schedule->fresh(), 'active', 'Resume setelah maintenance');
        $this->service()->transition($this->schedule->fresh(), 'ended', 'Era jadwal selesai');

        $this->assertEquals($executionBefore, DB::table('pm_executions')->where('id', $execution->id)->first());
        $this->assertEquals($historyBefore, DB::table('pm_execution_history')->where('pm_execution_id', $execution->id)->orderBy('id')->get()->toArray());
        $this->assertEquals($dateBefore, DB::table('pm_schedule_dates')->where('id', $date->id)->first());
    }

    /**
     * Characterization proof: an already started canonical execution remains
     * completable through review approval after Schedule is PAUSED/ENDED.
     * This intentionally tests the smallest existing service-level continuation
     * seam; new-start gating is outside Slice B.
     */
    public function test_started_execution_can_be_approved_after_schedule_end_without_new_execution(): void
    {
        // Fixture executor minimal: satu part dengan standard action agar seam
        // submit produksi dapat memvalidasi item execution.
        $part = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $this->schedule->pm_checksheet_machine_id,
            'part_name' => 'Motor Drive',
            'is_active' => true,
        ]);
        $standard = PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $part->id,
            'standard_name' => 'Cek suhu motor',
            'input_type' => 'action',
            'action_options' => ['OK', 'NOT OK'],
            'is_required' => true,
            'is_active' => true,
        ]);

        $operator = User::query()->create([
            'name' => 'Lifecycle Operator',
            'username' => 'lifecycle.operator',
            'password' => 'password',
            'role' => 'operator',
            'is_active' => true,
        ]);
        $admin = User::query()->create([
            'name' => 'Lifecycle Review Admin',
            'username' => 'lifecycle.review.admin',
            'password' => 'password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $date = PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-09-12',
            'status' => 'scheduled',
        ]);

        $request = app('request');
        $executionService = app(PmExecutionService::class);
        $execution = $executionService->startExecutionOnly($request, $this->machine, $operator);
        $this->assertSame('in_progress', $execution->status);

        // Schedule PAUSED: canonical execution yang sudah dimulai harus tetap
        // dapat lanjut ke submit (seam produksi PmExecutionService::submit).
        $this->service()->transition($this->schedule, 'paused', 'Pause untuk inspeksi');

        $submitted = $executionService->submit(
            $request,
            $this->machine,
            $operator,
            [$standard->id => 'OK'],
            [],
            [],
            (int) $execution->revision,
        );
        $this->assertSame('waiting_review', $submitted->status);

        // Schedule ENDED setelah submit: approval tetap boleh menuntaskan
        // pekerjaan yang sudah menunggu review (continuation-only contract).
        $this->service()->transition($this->schedule->fresh(), 'ended', 'Akhiri era jadwal');

        app(PmReviewService::class)->approve(
            $request,
            $submitted->fresh(),
            $admin,
            'Disetujui setelah pekerjaan selesai',
            (int) $submitted->revision,
        );

        // Satu canonical execution yang sama menuntung review sampai approved.
        $this->assertSame(
            1,
            PmExecution::query()->withTrashed()->where('pm_schedule_date_id', $date->id)->count(),
        );
        $this->assertDatabaseHas('pm_executions', [
            'id' => $execution->id,
            'pm_schedule_date_id' => $date->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $date->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('pm_execution_history', [
            'pm_execution_id' => $execution->id,
            'field_name' => 'Status PM',
            'old_value' => 'waiting_review',
            'new_value' => 'approved',
        ]);
    }
}
