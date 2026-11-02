<?php

namespace Tests\Feature\Feature\Report;

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\PM\MachineLifecycleService;
use App\Services\PM\ScheduleLifecycleService;
use App\Services\Report\ReportPmService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReportPmScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    private Location $location;

    private Machine $activeMachine;

    private Machine $inactiveMachine;

    private Machine $retiredMachine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'Asia/Jakarta'));

        $this->location = Location::query()->create([
            'location_code' => 'LOC-PM-SCOPE',
            'location_name' => 'Report Scope Location',
            'is_active' => true,
        ]);
        $this->admin = User::query()->create([
            'name' => 'Report Scope Admin',
            'username' => 'report.scope.admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->operator = User::query()->create([
            'name' => 'Report Scope Operator',
            'username' => 'report.scope.operator',
            'password' => Hash::make('password'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->activeMachine = $this->createMachine('MC-SCOPE-A');
        $this->inactiveMachine = $this->createMachine('MC-SCOPE-I');
        $this->retiredMachine = $this->createMachine('MC-SCOPE-R');
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_default_report_scope_includes_month_boundaries_and_only_eligible_older_work(): void
    {
        $occurrences = $this->createScopeOccurrences();
        $this->actingAs($this->admin);

        $firstPage = $this->get('/report/pm');
        $secondPage = $this->get('/report/pm?page=2');

        $expected = $this->occurrenceIds($occurrences, [
            'month_end_scheduled',
            'month_start_approved',
            'month_start_overdue',
            'older_overdue',
            'older_paused_overdue',
            'older_missed',
            'older_in_progress',
            'older_waiting_review',
            'retired_in_progress',
            'retired_waiting_review',
        ]);
        $actual = array_merge($this->reportOccurrenceIds($firstPage), $this->reportOccurrenceIds($secondPage));
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertSame(10, $firstPage['rows']->total(), 'Pagination counts PM jobs, not historical exclusions or child rows.');
    }

    public function test_screen_default_scope_does_not_change_export_filters_or_item_grain(): void
    {
        $occurrences = $this->createScopeOccurrences();
        $rows = app(ReportPmService::class)->exportRows([]);
        $actualOccurrenceIds = array_map('intval', $rows->pluck('schedule_date_id')->all());
        sort($actualOccurrenceIds);

        $expectedOccurrenceIds = $this->occurrenceIds($occurrences, [
            'month_end_scheduled',
            'month_start_approved',
            'month_start_overdue',
            'older_overdue',
            'older_missed',
            'older_in_progress',
            'older_paused_overdue',
            'older_ended_overdue',
            'older_ended_missed',
            'older_waiting_review',
            'older_approved',
            'inactive_overdue',
            'retired_missed',
            'retired_in_progress',
            'retired_waiting_review',
        ]);
        $approvedOccurrenceIds = array_map(
            'intval',
            app(ReportPmService::class)->exportRows(['status' => 'approved'])->pluck('schedule_date_id')->all(),
        );
        sort($approvedOccurrenceIds);

        $this->assertSame($expectedOccurrenceIds, $actualOccurrenceIds);
        $this->assertCount(15, $rows);
        $this->assertSame(
            $this->occurrenceIds($occurrences, ['month_start_approved', 'older_approved']),
            $approvedOccurrenceIds,
        );
        $this->assertSame(
            $this->occurrenceIds($occurrences, ['older_approved']),
            array_map('intval', app(ReportPmService::class)->exportRows([
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'status' => 'approved',
            ])->pluck('schedule_date_id')->all()),
        );
    }

    public function test_status_only_approved_keeps_older_approved_out_of_default_scope(): void
    {
        $occurrences = $this->createScopeOccurrences();

        $response = $this->actingAs($this->admin)->get('/report/pm?status=approved');

        $this->assertSame($this->occurrenceIds($occurrences, ['month_start_approved']), $this->reportOccurrenceIds($response));
    }

    public function test_status_only_overdue_includes_current_and_eligible_older_but_not_inactive_history(): void
    {
        $occurrences = $this->createScopeOccurrences();

        $response = $this->actingAs($this->admin)->get('/report/pm?status=overdue');

        $this->assertSame(
            $this->occurrenceIds($occurrences, ['month_start_overdue', 'older_overdue', 'older_paused_overdue']),
            $this->reportOccurrenceIds($response),
        );
    }

    public function test_explicit_previous_month_approved_filter_restores_historical_approval(): void
    {
        $occurrences = $this->createScopeOccurrences();

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'status' => 'approved',
        ]));

        $this->assertSame($this->occurrenceIds($occurrences, ['older_approved']), $this->reportOccurrenceIds($response));
    }

    public function test_explicit_historical_dates_include_inactive_history_without_unrelated_backlog(): void
    {
        $occurrences = $this->createScopeOccurrences();

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'start_date' => '2026-09-29',
            'end_date' => '2026-09-30',
        ]));

        $this->assertSame(
            $this->occurrenceIds($occurrences, ['older_overdue', 'inactive_overdue']),
            $this->reportOccurrenceIds($response),
        );
    }

    public function test_explicit_historical_dates_include_ended_schedule_occurrences(): void
    {
        $occurrences = $this->createScopeOccurrences();

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'start_date' => '2026-09-19',
            'end_date' => '2026-09-20',
        ]));

        $this->assertSame(
            $this->occurrenceIds($occurrences, ['older_ended_overdue', 'older_ended_missed']),
            $this->reportOccurrenceIds($response),
        );
    }

    private function createMachine(string $machineCode): Machine
    {
        return Machine::query()->create([
            'location_id' => $this->location->id,
            'machine_code' => $machineCode,
            'machine_name' => $machineCode,
            'qr_token' => 'qr-'.$machineCode,
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function createScopeOccurrences(): array
    {
        // Overdue September tetap persisted sampai cron catch-up berikutnya; Report tidak menjalankan scheduler.
        $occurrences = [
            'month_end_scheduled' => $this->createOccurrence('MES', $this->activeMachine, '2026-10-31', 'scheduled'),
            'month_start_approved' => $this->createOccurrence('MSA', $this->activeMachine, '2026-10-01', 'approved', 'approved'),
            'month_start_overdue' => $this->createOccurrence('MSO', $this->activeMachine, '2026-10-01', 'overdue'),
            'older_overdue' => $this->createOccurrence('OOV', $this->activeMachine, '2026-09-30', 'overdue'),
            'older_paused_overdue' => $this->createOccurrence('OPV', $this->activeMachine, '2026-09-21', 'overdue'),
            'older_ended_overdue' => $this->createOccurrence('EOV', $this->activeMachine, '2026-09-20', 'overdue'),
            'older_ended_missed' => $this->createOccurrence('EMI', $this->activeMachine, '2026-09-19', 'missed'),
            'older_missed' => $this->createOccurrence('OMI', $this->activeMachine, '2026-09-28', 'missed'),
            'older_in_progress' => $this->createOccurrence('OIP', $this->activeMachine, '2026-09-27', 'in_progress', 'in_progress'),
            'older_waiting_review' => $this->createOccurrence('OWR', $this->activeMachine, '2026-09-26', 'waiting_review', 'waiting_review'),
            'older_approved' => $this->createOccurrence('OAP', $this->activeMachine, '2026-09-25', 'approved', 'approved'),
            'inactive_overdue' => $this->createOccurrence('IOV', $this->inactiveMachine, '2026-09-30', 'overdue'),
            'retired_missed' => $this->createOccurrence('RMI', $this->retiredMachine, '2026-09-24', 'missed'),
            'retired_in_progress' => $this->createOccurrence('RIP', $this->retiredMachine, '2026-09-23', 'in_progress', 'in_progress'),
            'retired_waiting_review' => $this->createOccurrence('RWR', $this->retiredMachine, '2026-09-22', 'waiting_review', 'waiting_review'),
        ];

        // Lifecycle berubah melalui layanan resmi; occurrence dan execution yang sudah ada tetap historis.
        $request = Request::create('/pm/test', 'PATCH');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
        app(MachineLifecycleService::class)->transition($this->inactiveMachine, 'inactive');
        app(MachineLifecycleService::class)->transition($this->retiredMachine, 'retired');
        $scheduleLifecycle = app(ScheduleLifecycleService::class);
        $scheduleLifecycle->transition(
            PmScheduleDate::query()->findOrFail($occurrences['older_paused_overdue'])->schedule,
            'paused',
            'Schedule lama dijeda untuk verifikasi Report',
        );
        $scheduleLifecycle->transition(
            PmScheduleDate::query()->findOrFail($occurrences['older_ended_overdue'])->schedule,
            'ended',
            'Schedule lama diakhiri untuk verifikasi Report',
        );
        $scheduleLifecycle->transition(
            PmScheduleDate::query()->findOrFail($occurrences['older_ended_missed'])->schedule,
            'ended',
            'Schedule lama diakhiri untuk verifikasi Report',
        );

        return $occurrences;
    }

    private function createOccurrence(
        string $key,
        Machine $machine,
        string $scheduledDate,
        string $scheduleStatus,
        ?string $executionStatus = null,
    ): int {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'CHK-SCOPE-'.$key,
            'checksheet_name' => 'Checksheet '.$key,
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
            'frequency_type' => 'daily',
            'start_date' => $scheduledDate,
            'generate_until' => Carbon::parse($scheduledDate)->addMonth()->toDateString(),
            'is_active' => true,
            'lifecycle_status' => 'active',
            'created_by' => $this->admin->id,
        ]);
        $occurrence = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => $scheduledDate,
            'status' => $scheduleStatus,
            'status_changed_at' => now(),
        ]);

        if ($executionStatus !== null) {
            $startedAt = Carbon::parse($scheduledDate.' 08:00:00', 'Asia/Jakarta');
            $execution = PmExecution::query()->create([
                'pm_schedule_date_id' => $occurrence->id,
                'machine_id' => $machine->id,
                'operator_id' => $this->operator->id,
                'operator_name_snapshot' => $this->operator->name,
                'machine_code_snapshot' => $machine->machine_code,
                'machine_name_snapshot' => $machine->machine_name,
                'location_code_snapshot' => $this->location->location_code,
                'location_name_snapshot' => $this->location->location_name,
                'status' => $executionStatus,
                'started_at' => $startedAt,
                'submitted_at' => $executionStatus !== 'in_progress' ? $startedAt->copy()->addHour() : null,
                'approved_at' => $executionStatus === 'approved' ? $startedAt->copy()->addHours(2) : null,
                'approved_by' => $executionStatus === 'approved' ? $this->admin->id : null,
                'approved_by_name_snapshot' => $executionStatus === 'approved' ? $this->admin->name : null,
            ]);
            $execution->items()->create([
                'part_name_snapshot' => 'Part '.$key,
                'standard_name_snapshot' => 'Standard '.$key,
                'input_type_snapshot' => 'number',
                'number_value' => 45,
                'is_warning' => false,
            ]);
        }

        return $occurrence->id;
    }

    /**
     * @param  array<string, int>  $occurrences
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function occurrenceIds(array $occurrences, array $keys): array
    {
        $ids = array_map(fn (string $key): int => $occurrences[$key], $keys);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function reportOccurrenceIds(TestResponse $response): array
    {
        $ids = $response['rows']->pluck('schedule_date_id')->map(fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }
}
