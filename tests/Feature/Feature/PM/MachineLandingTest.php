<?php

namespace Tests\Feature\Feature\PM;

use App\Models\Breakdown;
use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MachineLandingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected Machine $activeMachine;

    protected Machine $inactiveMachine;

    protected function setUp(): void
    {
        parent::setUp();

        $location = Location::query()->create([
            'location_code' => 'LOC-01',
            'location_name' => 'Line 1, Block B',
            'is_active' => true,
        ]);

        $this->activeMachine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MCH-001',
            'machine_name' => 'CNC Milling',
            'qr_token' => 'qr-mch-001',
            'is_active' => true,
        ]);

        $this->inactiveMachine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MCH-002',
            'machine_name' => 'Sealing Machine',
            'qr_token' => 'qr-mch-002',
            'is_active' => false,
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
    }

    public function test_qr_invalid_redirects_to_machine_not_found(): void
    {
        $response = $this->get('/qr/unknown-token');

        $response->assertRedirect('/machine-not-found');
    }

    public function test_machine_landing_shows_schedule_and_open_breakdown_cards(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-001',
            'checksheet_name' => 'PM CNC',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->activeMachine->id,
            'created_by' => $this->admin->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'start_date' => now()->subMonth()->toDateString(),
            'generate_until' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'status' => 'approved',
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        Breakdown::query()->create([
            'breakdown_code' => 'BRK-001',
            'machine_id' => $this->activeMachine->id,
            'machine_name_snapshot' => $this->activeMachine->machine_name,
            'location_name_snapshot' => 'Line 1, Block B',
            'part_name_snapshot' => 'Spindle',
            'problem' => 'Vibration high',
            'status' => 'open',
            'breakdown_at' => now()->subHours(2),
            'created_by' => $this->operator->id,
            'created_by_name_snapshot' => $this->operator->name,
        ]);

        $response = $this->actingAs($this->operator)->get('/machines/MCH-001');

        $response->assertOk();
        $response->assertSee('Machine Status');
        $response->assertSee('CNC Milling');
        $response->assertSee('Open Breakdowns');
        $response->assertSee('Spindle');
        $response->assertSee('PM Executor');
    }

    public function test_operator_sees_pm_executor_navigable_control_with_correct_href(): void
    {
        // Buat checksheet, assignment, schedule, dan schedule date agar landing page
        // mendeteksi hasActivePmSchedule = true sehingga PM Executor tidak disabled.
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-NAV',
            'checksheet_name' => 'PM Navigation Test',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->activeMachine->id,
            'created_by' => $this->admin->id,
        ]);
        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'frequency_type' => 'daily',
            'start_date' => now()->subWeek()->toDateString(),
            'generate_until' => now()->addWeek()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->operator)->get('/machines/MCH-001');

        $response->assertOk();
        // Buktikan href menuju route pm-executor.show untuk mesin ini.
        $executorHref = 'href="'.route('pm-executor.show', $this->activeMachine).'"';
        $response->assertSee($executorHref, false);
        // Kontrol operator harus benar-benar dapat diklik, bukan sekadar memiliki href.
        $response->assertDontSee('pointer-events-none opacity-50', false);
        $response->assertDontSee('Admin tidak mengerjakan PM Executor.');
        $response->assertDontSee('Tidak ada jadwal PM aktif.');
    }

    public function test_admin_sees_pm_executor_control_as_disabled_after_role_check(): void
    {
        $response = $this->actingAs($this->admin)->get('/machines/MCH-001');

        $response->assertOk();
        // Link tetap menuju route yang benar dan class disabled memberi affordance visual.
        // Perlindungan aktual tetap ditegakkan oleh middleware prime.role:operator (403).
        $response->assertSee('href="'.route('pm-executor.show', $this->activeMachine).'"', false);
        $response->assertSee('pointer-events-none opacity-50', false);
        $response->assertSee('Admin tidak mengerjakan PM Executor.');
    }

    public function test_machine_landing_uses_latest_valid_execution_for_last_pm_card(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-002',
            'checksheet_name' => 'PM CNC Lanjutan',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->activeMachine->id,
            'created_by' => $this->admin->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [2],
            'start_date' => now()->subMonth()->toDateString(),
            'generate_until' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $oldScheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => '2026-06-05',
            'status' => 'missed',
        ]);

        $waitingReviewScheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => '2026-06-12',
            'status' => 'waiting_review',
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => '2026-06-17',
            'status' => 'scheduled',
        ]);

        PmExecution::query()->create([
            'pm_schedule_date_id' => $oldScheduleDate->id,
            'machine_id' => $this->activeMachine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'approved',
            'started_at' => now()->subDays(8),
            'submitted_at' => '2026-06-01 08:00:00',
            'approved_at' => '2026-06-01 09:00:00',
            'approved_by' => $this->admin->id,
            'approved_by_name_snapshot' => $this->admin->name,
        ]);

        PmExecution::query()->create([
            'pm_schedule_date_id' => $waitingReviewScheduleDate->id,
            'machine_id' => $this->activeMachine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'waiting_review',
            'started_at' => '2026-06-10 09:00:00',
            'submitted_at' => '2026-06-10 10:15:00',
        ]);

        $response = $this->actingAs($this->operator)->get('/machines/MCH-001');

        $response->assertOk();
        $response->assertSee('10 Jun 2026');
        $response->assertSee('Waiting Review');
        $response->assertDontSee('05 Jun 2026');
        $response->assertSee('17 Jun 2026');
    }

    public function test_machine_landing_shows_dash_for_last_pm_when_no_valid_execution_exists(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-003',
            'checksheet_name' => 'PM CNC Tanpa Eksekusi',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->activeMachine->id,
            'created_by' => $this->admin->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [4],
            'start_date' => now()->subWeek()->toDateString(),
            'generate_until' => now()->addWeek()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->operator)->get('/machines/MCH-001');

        $response->assertOk();
        $response->assertSeeTextInOrder(['Terakhir Melakukan PM', '-', 'Belum ada data']);
        $response->assertSee('Belum ada data');
    }

    public function test_machine_landing_prioritizes_unfinished_schedule_for_next_pm_card(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-004',
            'checksheet_name' => 'PM CNC Overdue',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->activeMachine->id,
            'created_by' => $this->admin->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [1],
            'start_date' => now()->subMonth()->toDateString(),
            'generate_until' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => '2026-06-09',
            'status' => 'overdue',
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->activeMachine->id,
            'scheduled_date' => '2026-06-16',
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->operator)->get('/machines/MCH-001');

        $response->assertOk();
        $response->assertSee('09 Jun 2026');
        $response->assertSee('Overdue');
    }

    public function test_admin_sees_pm_executor_disabled_message(): void
    {
        $response = $this->actingAs($this->admin)->get('/machines/MCH-001');

        $response->assertOk();
        $response->assertSee('Admin tidak mengerjakan PM Executor.');
    }

    public function test_inactive_machine_disables_new_transactions(): void
    {
        $response = $this->actingAs($this->operator)->get('/machines/MCH-002');

        $response->assertOk();
        $response->assertSee('Mesin nonaktif. Transaksi baru tidak dapat dibuat.');
    }

    public function test_operator_without_active_schedule_sees_disabled_reason(): void
    {
        $response = $this->actingAs($this->operator)->get('/machines/MCH-001');

        $response->assertOk();
        $response->assertSee('Tidak ada jadwal PM aktif.');
        $response->assertSee('pointer-events-none opacity-50', false);
    }

    public function test_operator_machine_back_button_uses_machine_access_return_target(): void
    {
        $response = $this->actingAs($this->operator)
            ->withSession([
                'machine_access_return_url' => route('machine-access'),
            ])
            ->get('/machines/MCH-001');

        $response->assertOk();
        $response->assertSee('href="'.route('machine-access').'"', false);
    }

    /**
     * TASK-006 Slice C (koreksi B-3): mesin retired dengan era ENDED dan
     * execution canonical in_progress tetap dapat melanjutkan PM.
     */
    private function createRetiredContinuationFixture(string $code, string $qrToken, bool $withExecution): Machine
    {
        $machine = Machine::query()->create([
            'location_id' => $this->activeMachine->location_id,
            'machine_code' => $code,
            'machine_name' => 'Mesin '.$code,
            'qr_token' => $qrToken,
            'is_active' => false,
            'lifecycle_status' => 'retired',
        ]);

        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-'.$code,
            'checksheet_name' => 'Checksheet '.$code,
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
            'operational_from' => now()->subMonth()->toDateString(),
            'start_date' => now()->subMonth()->toDateString(),
            'generate_until' => now()->toDateString(),
            'is_active' => false,
            'lifecycle_status' => 'ended',
            'created_by' => $this->admin->id,
        ]);

        $occurrence = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
        ]);

        if ($withExecution) {
            PmExecution::query()->create([
                'pm_schedule_date_id' => $occurrence->id,
                'machine_id' => $machine->id,
                'operator_id' => $this->operator->id,
                'operator_name_snapshot' => $this->operator->name,
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
        }

        return $machine;
    }

    /**
     * Memeriksa class disabled pada anchor PM Executor itu sendiri, bukan
     * elemen lain yang memang disabled pada mesin non-live.
     */
    private function executorControlMarkup(string $html, Machine $machine): string
    {
        $anchorStart = strpos($html, 'href="'.route('pm-executor.show', $machine).'"');
        $this->assertNotFalse($anchorStart, 'Anchor PM Executor tidak ditemukan pada halaman landing.');

        return substr($html, (int) $anchorStart, 400);
    }

    public function test_retired_machine_with_continuable_execution_enables_executor_link(): void
    {
        $machine = $this->createRetiredContinuationFixture('MCH-RET-01', 'qr-mch-ret-01', true);

        $response = $this->actingAs($this->operator)->get("/machines/{$machine->machine_code}");

        $response->assertOk();
        $response->assertSee('href="'.route('pm-executor.show', $machine).'"', false);
        // Notice mesin non-live tetap tampil sebagai informasi.
        $response->assertSee('Mesin nonaktif. Transaksi baru tidak dapat dibuat.');
        $response->assertDontSee('Mesin nonaktif. PM Executor tidak dapat dibuka.');

        // Kontrol PM Executor benar-benar aktif untuk continuation canonical.
        $this->assertStringNotContainsString(
            'pointer-events-none',
            $this->executorControlMarkup((string) $response->getContent(), $machine)
        );
    }

    public function test_admin_with_continuable_execution_keeps_executor_control_disabled(): void
    {
        $machine = $this->createRetiredContinuationFixture('MCH-RET-02', 'qr-mch-ret-02', true);

        $response = $this->actingAs($this->admin)->get("/machines/{$machine->machine_code}");

        $response->assertOk();
        $response->assertSee('href="'.route('pm-executor.show', $machine).'"', false);
        $response->assertSee('Mesin nonaktif. Transaksi baru tidak dapat dibuat.');
        // Blokir role tidak pernah dilewati oleh exception continuation.
        $this->assertStringContainsString(
            'pointer-events-none',
            $this->executorControlMarkup((string) $response->getContent(), $machine)
        );
    }

    public function test_retired_machine_without_continuation_keeps_executor_control_disabled(): void
    {
        $machine = $this->createRetiredContinuationFixture('MCH-RET-03', 'qr-mch-ret-03', false);

        $response = $this->actingAs($this->operator)->get("/machines/{$machine->machine_code}");

        $response->assertOk();
        $response->assertSee('Mesin nonaktif. Transaksi baru tidak dapat dibuat.');
        $this->assertStringContainsString(
            'pointer-events-none',
            $this->executorControlMarkup((string) $response->getContent(), $machine)
        );
    }
}
