<?php

namespace Tests\Feature\Dashboard;

use App\Models\Breakdown;
use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetPart;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_default_filter_current_month_until_today(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('dateRange.start', now()->startOfMonth()->toDateString())
            ->where('dateRange.end', now()->toDateString()));
    }

    public function test_dashboard_validates_date_range(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->get('/dashboard?start_date=2026-06-10&end_date=2026-06-01');

        $response->assertRedirect();
        $response->assertSessionHasErrors('end_date');
    }

    public function test_dashboard_renders_kpi_based_on_filtered_data(): void
    {
        $admin = $this->createAdmin();

        $location = Location::query()->create([
            'location_code' => 'LOC-01',
            'location_name' => 'Area Produksi',
            'is_active' => true,
        ]);

        $machineA = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MCH-001',
            'machine_name' => 'Air Compressor G1',
            'qr_token' => 'qr-air-compressor-g1',
            'is_active' => true,
        ]);

        $machineB = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MCH-002',
            'machine_name' => 'Cooling Tower N1',
            'qr_token' => 'qr-cooling-tower-n1',
            'is_active' => false,
        ]);

        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'CHK-001',
            'checksheet_name' => 'PM Unit Produksi',
            'description' => 'Checksheet Dashboard Test',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $checksheetMachineA = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $machineA->id,
            'assigned_at' => now(),
            'created_by' => $admin->id,
        ]);

        $scheduleA = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachineA->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [1],
            'monthly_day' => null,
            'start_date' => '2026-06-01',
            'generate_until' => '2026-06-30',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $scheduleA->id,
            'machine_id' => $machineA->id,
            'scheduled_date' => '2026-06-03',
            'status' => 'waiting_review',
            'status_changed_at' => now(),
            'generated_at' => now(),
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $scheduleA->id,
            'machine_id' => $machineA->id,
            'scheduled_date' => '2026-06-05',
            'status' => 'approved',
            'status_changed_at' => now(),
            'generated_at' => now(),
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $scheduleA->id,
            'machine_id' => $machineA->id,
            'scheduled_date' => '2026-06-08',
            'status' => 'overdue',
            'status_changed_at' => now(),
            'generated_at' => now(),
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $scheduleA->id,
            'machine_id' => $machineA->id,
            'scheduled_date' => '2026-06-10',
            'status' => 'missed',
            'status_changed_at' => now(),
            'generated_at' => now(),
        ]);

        $part = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachineA->id,
            'part_name' => 'Motor',
            'description' => 'Motor Unit',
            'is_active' => true,
        ]);

        Breakdown::query()->create([
            'breakdown_code' => 'BD-001',
            'machine_id' => $machineA->id,
            'pm_checksheet_part_id' => $part->id,
            'machine_name_snapshot' => $machineA->machine_name,
            'location_name_snapshot' => $location->location_name,
            'part_name_snapshot' => 'Motor',
            'custom_part_name' => null,
            'problem' => 'Getaran tinggi',
            'open_note' => 'Perlu inspeksi',
            'status' => 'closed',
            'breakdown_at' => '2026-06-02 08:00:00',
            'created_by' => $admin->id,
            'created_by_name_snapshot' => $admin->name,
            'root_cause' => 'Bearing aus',
            'action_taken' => 'Ganti bearing',
            'countermeasure' => 'Jadwal pelumasan',
            'closed_at' => '2026-06-02 10:00:00',
            'closed_by' => $admin->id,
            'closed_by_name_snapshot' => $admin->name,
            'downtime_minutes' => 120,
        ]);

        Breakdown::query()->create([
            'breakdown_code' => 'BD-002',
            'machine_id' => $machineA->id,
            'pm_checksheet_part_id' => $part->id,
            'machine_name_snapshot' => $machineA->machine_name,
            'location_name_snapshot' => $location->location_name,
            'part_name_snapshot' => 'Motor',
            'custom_part_name' => null,
            'problem' => 'Suhu naik',
            'open_note' => 'Butuh cleaning',
            'status' => 'closed',
            'breakdown_at' => '2026-06-04 08:00:00',
            'created_by' => $admin->id,
            'created_by_name_snapshot' => $admin->name,
            'root_cause' => 'Debu menumpuk',
            'action_taken' => 'Cleaning fan',
            'countermeasure' => 'Preventive cleaning',
            'closed_at' => '2026-06-04 09:00:00',
            'closed_by' => $admin->id,
            'closed_by_name_snapshot' => $admin->name,
            'downtime_minutes' => 60,
        ]);

        Breakdown::query()->create([
            'breakdown_code' => 'BD-003',
            'machine_id' => $machineA->id,
            'pm_checksheet_part_id' => $part->id,
            'machine_name_snapshot' => $machineA->machine_name,
            'location_name_snapshot' => $location->location_name,
            'part_name_snapshot' => 'Motor',
            'custom_part_name' => null,
            'problem' => 'Alarm aktif',
            'open_note' => 'Menunggu sparepart',
            'status' => 'open',
            'breakdown_at' => '2026-06-07 08:00:00',
            'created_by' => $admin->id,
            'created_by_name_snapshot' => $admin->name,
            'root_cause' => null,
            'action_taken' => null,
            'countermeasure' => null,
            'closed_at' => null,
            'closed_by' => null,
            'closed_by_name_snapshot' => null,
            'downtime_minutes' => null,
        ]);

        $response = $this->actingAs($admin)->get('/dashboard?start_date=2026-06-01&end_date=2026-06-30');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('machineSummary.total', 2)
            ->where('machineSummary.active', 1)
            ->where('machineSummary.inactive', 1)
            ->where('pmStatus.completed', 2)
            ->where('pmStatus.overdue', 2)
            ->where('pmStatus.total', 4)
            ->where('breakdownDurationSeries.0.total_breakdown_count', 2)
            ->where('closedBreakdownLogs.0.part_name', 'Motor')
            ->where('mtbfHours', 48)
            ->where('mtbrHours', 1.5)
            ->where('dateRange.start', '2026-06-01')
            ->where('dateRange.end', '2026-06-30'));

    }

    public function test_dashboard_breakdown_status_shows_all_closed_logs_in_selected_range(): void
    {
        $admin = $this->createAdmin();

        $location = Location::query()->create([
            'location_code' => 'LOC-02',
            'location_name' => 'Area Utility',
            'is_active' => true,
        ]);

        $machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MCH-100',
            'machine_name' => 'Boiler Feed Pump',
            'qr_token' => 'qr-boiler-feed-pump',
            'is_active' => true,
        ]);

        foreach (range(1, 6) as $index) {
            Breakdown::query()->create([
                'breakdown_code' => 'BD-A0'.$index,
                'machine_id' => $machine->id,
                'machine_name_snapshot' => $machine->machine_name,
                'location_name_snapshot' => $location->location_name,
                'part_name_snapshot' => 'Part '.$index,
                'custom_part_name' => null,
                'problem' => 'Problem '.$index,
                'open_note' => 'Catatan '.$index,
                'status' => 'closed',
                'breakdown_at' => now()->startOfMonth()->addDays($index)->setTime(8, 0),
                'created_by' => $admin->id,
                'created_by_name_snapshot' => $admin->name,
                'root_cause' => 'Cause '.$index,
                'action_taken' => 'Action '.$index,
                'countermeasure' => 'Counter '.$index,
                'closed_at' => now()->startOfMonth()->addDays($index)->setTime(10, 0),
                'closed_by' => $admin->id,
                'closed_by_name_snapshot' => $admin->name,
                'downtime_minutes' => 120,
            ]);
        }

        $response = $this->actingAs($admin)->get('/dashboard?start_date='.now()->startOfMonth()->toDateString().'&end_date='.now()->endOfMonth()->toDateString());

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('closedBreakdownLogs', 6)
            ->where('closedBreakdownLogs.0.part_name', 'Part 6')
            ->where('closedBreakdownLogs.5.part_name', 'Part 1'));
    }

    protected function createAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin PRIME',
            'username' => 'admin.prime',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}
