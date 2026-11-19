<?php

namespace Tests\Feature\Feature\PM;

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetPart;
use App\Models\PmChecksheetStandard;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\PrimeNotification;
use App\Models\User;
use App\Services\PM\MachineLifecycleService;
use App\Services\PM\PmExecutionService;
use App\Services\PM\ScheduleLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Test terfokus untuk TASK-001 — PM Status Transition Safety.
 *
 * Membuktikan bahwa cron hanya menandai occurrence yang belum dimulai
 * sebagai overdue atau missed, tanpa memutus pekerjaan yang sudah memiliki execution.
 */
class SyncPmScheduleStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Machine $machine;

    protected PmSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $location = Location::query()->create([
            'location_code' => 'LOC-SYNC',
            'location_name' => 'Sync Line',
            'is_active' => true,
        ]);

        $this->machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-SYNC',
            'machine_name' => 'Sync Machine',
            'qr_token' => 'qr-sync',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-SYNC',
            'checksheet_name' => 'Sync Checksheet',
            'is_active' => true,
        ]);

        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
        ]);

        $part = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'part_name' => 'Sync Part',
            'is_active' => true,
        ]);

        PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $part->id,
            'standard_name' => 'Sync Standard',
            'input_type' => 'number',
            'is_required' => true,
            'is_active' => true,
        ]);

        $this->schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'frequency_type' => 'daily',
            'start_date' => now()->subMonth()->toDateString(),
            'generate_until' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
    }

    /**
     * Membuat schedule date dengan status tertentu.
     */
    private function createScheduleDate(string $date, string $status = 'scheduled'): PmScheduleDate
    {
        return PmScheduleDate::query()->create([
            'pm_schedule_id' => $this->schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => $date,
            'status' => $status,
        ]);
    }

    /**
     * Membuat execution pada occurrence tertentu.
     */
    private function createExecution(PmScheduleDate $scheduleDate, bool $softDelete = false): PmExecution
    {
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDate->id,
            'machine_id' => $this->machine->id,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        if ($softDelete) {
            $execution->delete();
        }

        return $execution;
    }

    // ---- Normal Transition: scheduled → overdue ----

    /**
     * Membuktikan scheduled yang sudah melewati batas overdue menjadi overdue
     * dan menghasilkan pm_overdue.
     */
    public function test_scheduled_becomes_overdue_when_past_threshold(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('overdue', $scheduleDate->status);
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_overdue',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);
    }

    // ---- Normal Transition: overdue → missed ----

    /**
     * Membuktikan overdue yang sudah melewati batas missed menjadi missed
     * dan menghasilkan pm_missed.
     */
    public function test_overdue_becomes_missed_when_past_threshold(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDays(2)->toDateString(), 'overdue');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('missed', $scheduleDate->status);
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_missed',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);
    }

    // ---- Catch-up: stale scheduled → overdue → missed ----

    /**
     * Membuktikan stale scheduled yang sudah melewati threshold missed
     * tidak langsung menjadi missed, tetapi melewati scheduled → overdue → missed
     * dalam satu command run.
     */
    public function test_stale_scheduled_reaches_missed_through_valid_lifecycle(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDays(5)->toDateString(), 'scheduled');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('missed', $scheduleDate->status);
    }

    /**
     * Membuktikan catch-up dalam satu command run hanya menghasilkan pm_missed,
     * tidak membuat pm_overdue untuk transition intermediate.
     */
    public function test_catch_up_only_creates_final_missed_notification(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDays(5)->toDateString(), 'scheduled');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        // pm_overdue tidak dibuat untuk catch-up intermediate.
        $this->assertDatabaseMissing('notifications', [
            'notification_type' => 'pm_overdue',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);

        // pm_missed adalah satu-satunya notification final.
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_missed',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);
    }

    // ---- Protection: in_progress, waiting_review, approved ----

    /**
     * Membuktikan occurrence in_progress tidak berubah karena cron.
     */
    public function test_in_progress_does_not_become_overdue(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDay()->toDateString(), 'in_progress');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('in_progress', $scheduleDate->status);
    }

    /**
     * Membuktikan occurrence waiting_review tidak berubah karena cron.
     */
    public function test_waiting_review_does_not_become_missed(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDays(5)->toDateString(), 'waiting_review');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('waiting_review', $scheduleDate->status);
    }

    /**
     * Membuktikan occurrence approved tidak berubah karena cron.
     */
    public function test_approved_does_not_become_missed(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDays(5)->toDateString(), 'approved');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('approved', $scheduleDate->status);
    }

    // ---- Protected evidence: active dan soft-deleted execution ----

    /**
     * Membuktikan occurrence dengan execution aktif tidak berubah.
     */
    public function test_occurrence_with_active_execution_is_protected(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');
        $this->createExecution($scheduleDate, softDelete: false);

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('scheduled', $scheduleDate->status);
    }

    /**
     * Membuktikan occurrence dengan soft-deleted execution juga protected.
     */
    public function test_occurrence_with_soft_deleted_execution_is_protected(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');
        $this->createExecution($scheduleDate, softDelete: true);

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('scheduled', $scheduleDate->status);
    }

    // ---- Idempotency ----

    /**
     * Membuktikan repeated command tidak menghasilkan transition tambahan.
     */
    public function test_repeated_command_does_not_create_additional_transitions(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);
        $scheduleDate->refresh();
        $this->assertSame('overdue', $scheduleDate->status);

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);
        $scheduleDate->refresh();
        $this->assertSame('overdue', $scheduleDate->status);
    }

    /**
     * Membuktikan repeated command tidak menghasilkan duplicate notification.
     */
    public function test_repeated_command_does_not_duplicate_notifications(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);
        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $this->assertSame(1, PrimeNotification::query()
            ->where('notification_type', 'pm_overdue')
            ->where('related_table', 'pm_schedule_dates')
            ->where('related_id', $scheduleDate->id)
            ->count());
    }

    /**
     * Membuktikan normal transition pada dua waktu berbeda:
     * Hari 1: scheduled → overdue + pm_overdue.
     * Hari 2: overdue → missed + pm_missed, pm_overdue SEBELUMNYA tetap ada.
     */
    public function test_normal_transition_preserves_overdue_notification_when_becoming_missed(): void
    {
        // Menggunakan Carbon::setTestNow() agar deterministic dan tidak bergantung tanggal aktual.
        $day1 = Carbon::create(2026, 8, 19, 0, 0, 0);
        $yesterday = $day1->copy()->subDay();
        Carbon::setTestNow($day1);
        $scheduleDate = $this->createScheduleDate($yesterday->toDateString(), 'scheduled');

        // === Hari 1: scheduled → overdue ===
        Carbon::setTestNow($day1);
        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('overdue', $scheduleDate->status, 'Hari 1: status harus overdue.');
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_overdue',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notification_type' => 'pm_missed',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);
        $this->assertSame(1, PrimeNotification::query()
            ->where('notification_type', 'pm_overdue')
            ->where('related_table', 'pm_schedule_dates')
            ->where('related_id', $scheduleDate->id)
            ->count(), 'Hari 1: tepat satu pm_overdue.');

        // === Hari 2: overdue → missed ===
        // Majukan waktu satu hari agar scheduled_date < (today - 1 day) = kemarin.
        Carbon::setTestNow($day1->copy()->addDay());
        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $scheduleDate->refresh();
        $this->assertSame('missed', $scheduleDate->status, 'Hari 2: status harus missed.');

        // pm_overdue dari Hari 1 TIDAK boleh dihapus.
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_overdue',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);
        $this->assertSame(1, PrimeNotification::query()
            ->where('notification_type', 'pm_overdue')
            ->where('related_table', 'pm_schedule_dates')
            ->where('related_id', $scheduleDate->id)
            ->count(), 'Hari 2: pm_overdue sebelumnya harus tetap ada.');

        // pm_missed baru dibuat.
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_missed',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);
        $this->assertSame(1, PrimeNotification::query()
            ->where('notification_type', 'pm_missed')
            ->where('related_table', 'pm_schedule_dates')
            ->where('related_id', $scheduleDate->id)
            ->count(), 'Hari 2: tepat satu pm_missed.');

        // Kembalikan waktu ke normal.
        Carbon::setTestNow();
    }

    // ---- CAS rejection: PM start commit setelah seleksi cron ----

    /**
     * Membuktikan branch CAS ditolak ketika PM start commit in_progress SETELAH
     * cron memilih occurrence. Cron harus melewati transisi: occurrence tetap
     * in_progress, eksekusi canonical tunggal tetap in_progress, tidak ada
     * pm_overdue, dan counter overdue tidak bertambah.
     *
     * Listener query bersifat one-shot pada SELECT kandidat cron (bentuk SQL +
     * binding status), lalu menjalankan start PM yang nyata. Guard re-entrancy
     * mencegah listener menangkap query resolver start itu sendiri.
     */
    public function test_command_skips_overdue_transition_when_pm_start_commits_after_selection(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');

        $operator = User::query()->create([
            'name' => 'Operator Sync',
            'username' => 'operator.sync',
            'password' => Hash::make('password'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));

        $selectionListenerFired = false;
        $listenerArmed = true;

        DB::listen(function ($query) use (&$selectionListenerFired, &$listenerArmed, $operator, $request): void {
            // Re-entrancy guard: query yang dijalankan startExecutionOnly() di dalam
            // listener ini tidak boleh memicu listener lagi.
            if (! $listenerArmed) {
                return;
            }

            // Kandidat cron tidak memiliki predikat id/machine, sehingga bentuk SQL
            // dan binding status adalah satu-satunya penanda aman pada database test
            // terisolasi.
            if (! str_contains($query->sql, 'from "pm_schedule_dates"')
                || ! str_contains($query->sql, 'not exists')
                || ! in_array('scheduled', $query->bindings, true)) {
                return;
            }

            $listenerArmed = false;
            $selectionListenerFired = true;

            app(PmExecutionService::class)->startExecutionOnly(
                request: $request,
                machine: $this->machine,
                operator: $operator,
            );
        });

        Artisan::call('pm:sync-schedule-status');
        $output = Artisan::output();

        $this->assertTrue($selectionListenerFired, 'Listener seleksi kandidat cron harus terpicu.');
        $this->assertStringContainsString('Overdue: 0', $output);
        $this->assertSame('in_progress', $scheduleDate->fresh()->status);
        $this->assertSame(1, PmExecution::withTrashed()->where('pm_schedule_date_id', $scheduleDate->id)->count());
        $this->assertSame('in_progress', PmExecution::query()->where('pm_schedule_date_id', $scheduleDate->id)->value('status'));
        $this->assertDatabaseMissing('notifications', [
            'notification_type' => 'pm_overdue',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $scheduleDate->id,
        ]);
    }

    // ---- BR-PM-LC-003: era Schedule ENDED tidak lagi menua ----

    /**
     * Occurrence tanpa execution di bawah Schedule ENDED tidak boleh berubah
     * status, tidak membuat notifikasi, dan tidak menambah counter command.
     */
    public function test_ended_schedule_occurrences_do_not_age_and_produce_no_side_effects(): void
    {
        $this->schedule->forceFill(['lifecycle_status' => 'ended', 'is_active' => false])->save();

        $scheduled = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');
        $overdue = $this->createScheduleDate(now()->subDays(3)->toDateString(), 'overdue');
        $scheduledBefore = $scheduled->fresh()->getAttributes();
        $overdueBefore = $overdue->fresh()->getAttributes();

        Artisan::call('pm:sync-schedule-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Overdue: 0, Missed: 0', $output);
        $this->assertSame('scheduled', $scheduled->fresh()->status);
        $this->assertSame('overdue', $overdue->fresh()->status);
        $this->assertSame($scheduledBefore, $scheduled->fresh()->getAttributes(), 'Baris ENDED tidak boleh ditulis ulang.');
        $this->assertSame($overdueBefore, $overdue->fresh()->getAttributes(), 'Baris ENDED tidak boleh ditulis ulang.');
        $this->assertSame(0, PrimeNotification::query()->count());
    }

    /**
     * Occurrence PAUSED tidak menua, sementara assignment aktif lain tetap berjalan.
     */
    public function test_paused_schedule_occurrences_do_not_age_while_active_assignment_continues(): void
    {
        $this->schedule->forceFill(['lifecycle_status' => 'paused', 'is_active' => false])->save();

        $pausedScheduled = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');
        $pausedOverdue = $this->createScheduleDate(now()->subDays(3)->toDateString(), 'overdue');
        $pausedScheduledBefore = $pausedScheduled->fresh()->getAttributes();
        $pausedOverdueBefore = $pausedOverdue->fresh()->getAttributes();

        $activeMachine = Machine::query()->create([
            'location_id' => $this->machine->location_id,
            'machine_code' => 'MC-SYNC-ACTIVE',
            'machine_name' => 'Active Sync Machine',
            'qr_token' => 'qr-sync-active',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
        $activeAssignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $this->schedule->checksheetMachine->pm_checksheet_id,
            'machine_id' => $activeMachine->id,
        ]);
        $activeSchedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $activeAssignment->id,
            'frequency_type' => 'daily',
            'start_date' => now()->subMonth()->toDateString(),
            'generate_until' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
        $activeOccurrence = PmScheduleDate::query()->create([
            'pm_schedule_id' => $activeSchedule->id,
            'machine_id' => $activeMachine->id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        Artisan::call('pm:sync-schedule-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Overdue: 1, Missed: 0', $output);
        $this->assertSame('scheduled', $pausedScheduled->fresh()->status);
        $this->assertSame('overdue', $pausedOverdue->fresh()->status);
        $this->assertSame($pausedScheduledBefore, $pausedScheduled->fresh()->getAttributes());
        $this->assertSame($pausedOverdueBefore, $pausedOverdue->fresh()->getAttributes());
        $this->assertSame('overdue', $activeOccurrence->fresh()->status);
        $this->assertDatabaseMissing('notifications', [
            'related_table' => 'pm_schedule_dates',
            'related_id' => $pausedScheduled->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'related_table' => 'pm_schedule_dates',
            'related_id' => $pausedOverdue->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_overdue',
            'related_id' => $activeOccurrence->id,
        ]);
    }

    /**
     * Kegagalan insert notifikasi sesudah CAS tidak boleh menyisakan aging parsial.
     * Pause dijalankan setelah rollback, bukan sebagai transaksi konkuren palsu.
     */
    #[DataProvider('notificationFailureCases')]
    public function test_notification_failure_rolls_back_aging_before_pause(
        string $status,
        int $daysAgo,
        string $statusAtNotification,
    ): void {
        $this->travelTo(Carbon::create(2026, 11, 19, 12));
        $request = Request::create('/', 'PATCH');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
        $scheduleDate = $this->createScheduleDate(now()->subDays($daysAgo)->toDateString(), $status);
        $scheduleDate->forceFill(['status_changed_at' => now()->subDays(7)])->save();
        $before = $scheduleDate->fresh()->getAttributes();
        $observedStatus = null;
        $failure = new RuntimeException('notification persistence unavailable');
        $dispatcher = PrimeNotification::getEventDispatcher();
        PrimeNotification::setEventDispatcher(clone $dispatcher);

        try {
            PrimeNotification::creating(function () use ($scheduleDate, &$observedStatus, $failure): void {
                $observedStatus = $scheduleDate->fresh()->status;
                throw $failure;
            });

            try {
                Artisan::call('pm:sync-schedule-status');
                $this->fail('Kegagalan persistensi notifikasi harus diteruskan.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }
        } finally {
            PrimeNotification::setEventDispatcher($dispatcher);
            $this->travelBack();
        }

        $this->assertSame($statusAtNotification, $observedStatus, 'CAS harus sudah terjadi sebelum insert notifikasi gagal.');
        $this->assertSame($before, $scheduleDate->fresh()->getAttributes(), 'Status dan status_changed_at harus ikut rollback.');
        $this->assertSame(0, PrimeNotification::query()->count());

        app(ScheduleLifecycleService::class)->transition($this->schedule, 'paused', 'Pause setelah kegagalan notifikasi');
        $this->assertSame('paused', $this->schedule->fresh()->lifecycle_status);
        $this->assertDatabaseHas('user_activity_logs', [
            'table_name' => 'pm_schedules',
            'record_id' => $this->schedule->id,
            'action' => 'lifecycle_transition',
        ]);

        $this->artisan('pm:sync-schedule-status')
            ->expectsOutputToContain('Overdue: 0, Missed: 0')
            ->assertExitCode(0);
        $this->assertSame($before, $scheduleDate->fresh()->getAttributes());
        $this->assertSame(0, PrimeNotification::query()->count());
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function notificationFailureCases(): array
    {
        return [
            'scheduled ke overdue' => ['scheduled', 1, 'overdue'],
            'overdue ke missed' => ['overdue', 3, 'missed'],
        ];
    }

    /**
     * Pause nyata sesudah seleksi harus menang atas snapshot kandidat cron.
     * Interleaving satu koneksi ini tidak membuktikan eksklusi lock InnoDB.
     */
    #[DataProvider('pauseAfterSelectionCases')]
    public function test_command_skips_aging_when_pause_commits_after_selection(string $status, int $daysAgo): void
    {
        $request = Request::create('/', 'PATCH');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
        $scheduleDate = $this->createScheduleDate(now()->subDays($daysAgo)->toDateString(), $status);
        $before = $scheduleDate->fresh()->getAttributes();
        $selectionListenerFired = false;
        $listenerArmed = true;
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);

        try {
            $connection->listen(function ($query) use ($status, &$selectionListenerFired, &$listenerArmed): void {
                if (! $listenerArmed
                    || ! str_starts_with($query->sql, 'select')
                    || ! str_contains($query->sql, 'from "pm_schedule_dates"')
                    || ! str_contains($query->sql, 'not exists')
                    || ! in_array($status, $query->bindings, true)) {
                    return;
                }

                $listenerArmed = false;
                $selectionListenerFired = true;
                app(ScheduleLifecycleService::class)->transition($this->schedule, 'paused', 'Pause setelah seleksi cron');
            });

            $exitCode = Artisan::call('pm:sync-schedule-status');
            $output = Artisan::output();
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }

        $this->assertSame(0, $exitCode);
        $this->assertTrue($selectionListenerFired);
        $this->assertStringContainsString('Overdue: 0, Missed: 0', $output);
        $this->assertSame('paused', $this->schedule->fresh()->lifecycle_status);
        $this->assertSame($before, $scheduleDate->fresh()->getAttributes());
        $this->assertSame(0, PrimeNotification::query()->count());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function pauseAfterSelectionCases(): array
    {
        return [
            'seleksi scheduled' => ['scheduled', 1],
            'seleksi overdue' => ['overdue', 3],
        ];
    }

    /**
     * Soft-delete Schedule tidak menutup era. Schedule aktif tetap menua;
     * PAUSED dan ENDED tetap dikecualikan.
     */
    public function test_soft_deleted_schedule_with_non_ended_era_still_ages(): void
    {
        $this->schedule->delete();

        $scheduled = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');

        Artisan::call('pm:sync-schedule-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Overdue: 1, Missed: 0', $output);
        $this->assertSame('overdue', $scheduled->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_overdue',
            'related_id' => $scheduled->id,
        ]);
    }

    /**
     * Retirement yang commit SETELAH seleksi kandidat harus membuat CAS final
     * gagal: occurrence tetap scheduled, tanpa notifikasi/counter.
     */
    public function test_command_skips_transition_when_retirement_ends_schedule_after_selection(): void
    {
        $scheduleDate = $this->createScheduleDate(now()->subDay()->toDateString(), 'scheduled');

        $request = Request::create('/', 'PATCH');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);

        $selectionListenerFired = false;
        $listenerArmed = true;

        DB::listen(function ($query) use (&$selectionListenerFired, &$listenerArmed): void {
            // Re-entrancy guard: query retirement di dalam listener tidak boleh
            // memicu listener lagi.
            if (! $listenerArmed) {
                return;
            }

            if (! str_contains($query->sql, 'from "pm_schedule_dates"')
                || ! str_contains($query->sql, 'not exists')
                || ! in_array('scheduled', $query->bindings, true)) {
                return;
            }

            $listenerArmed = false;
            $selectionListenerFired = true;

            app(MachineLifecycleService::class)->transition($this->machine, 'retired');
        });

        Artisan::call('pm:sync-schedule-status');
        $output = Artisan::output();

        $this->assertTrue($selectionListenerFired, 'Listener seleksi kandidat cron harus terpicu.');
        $this->assertStringContainsString('Overdue: 0, Missed: 0', $output);
        $this->assertSame('retired', $this->machine->fresh()->lifecycle_status);
        $this->assertSame('ended', $this->schedule->fresh()->lifecycle_status);
        $this->assertSame('scheduled', $scheduleDate->fresh()->status);
        $this->assertSame(0, PrimeNotification::query()->count());
    }
}
