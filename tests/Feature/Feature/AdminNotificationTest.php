<?php

namespace Tests\Feature\Feature;

use App\Models\Location;
use App\Models\Machine;
use App\Models\NotificationRead;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\PrimeNotification;
use App\Models\User;
use App\Services\Notification\AdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminA;

    protected User $adminB;

    protected User $operator;

    protected Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $location = Location::query()->create([
            'location_code' => 'LOC-NTF-01',
            'location_name' => 'Area Notif',
            'is_active' => true,
        ]);

        $this->machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-NTF-01',
            'machine_name' => 'Machine Notification',
            'qr_token' => 'qr-machine-notification',
            'is_active' => true,
        ]);

        $this->adminA = User::query()->create([
            'name' => 'Admin A',
            'username' => 'admin.a',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->adminB = User::query()->create([
            'name' => 'Admin B',
            'username' => 'admin.b',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->operator = User::query()->create([
            'name' => 'Operator A',
            'username' => 'operator.a',
            'password' => Hash::make('password'),
            'role' => 'operator',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_fetch_notifications_with_unread_count(): void
    {
        $notificationA = PrimeNotification::query()->create([
            'notification_type' => 'pm_waiting_review',
            'title' => 'Menunggu Review: Hasil PM Mesin Machine Notification',
            'message' => 'Hai, proses Preventive Maintenance untuk mesin Machine Notification telah selesai dikerjakan. Mohon segera melakukan review agar proses ini dapat segera diselesaikan. Terima kasih atas kerja samanya!',
            'target_role' => 'admin',
            'related_table' => 'pm_executions',
            'related_id' => 101,
            'target_url' => '/pm/review/101',
        ]);

        PrimeNotification::query()->create([
            'notification_type' => 'breakdown_open',
            'title' => 'Laporan Breakdown: Mesin Machine Notification Breakdown Open',
            'message' => 'Hai, breakdown baru tercatat untuk mesin Machine Notification. Mohon bantuan teknisi untuk segera melakukan pengecekan dan perbaikan',
            'target_role' => 'admin',
            'related_table' => 'breakdowns',
            'related_id' => 202,
            'target_url' => '/breakdown/review/202',
        ]);

        NotificationRead::query()->create([
            'notification_id' => $notificationA->id,
            'user_id' => $this->adminA->id,
            'read_at' => now(),
        ]);

        $response = $this->actingAs($this->adminA)->getJson('/admin/notifications');

        $response->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(2, 'notifications');
    }

    public function test_operator_cannot_access_admin_notification_endpoints(): void
    {
        $this->actingAs($this->operator)
            ->get('/admin/notifications')
            ->assertRedirect('/403');

        $this->actingAs($this->operator)
            ->post('/admin/notifications/read-all')
            ->assertRedirect('/403');
    }

    public function test_mark_as_read_is_per_admin_user(): void
    {
        $notification = PrimeNotification::query()->create([
            'notification_type' => 'breakdown_closed',
            'title' => 'Laporan Breakdown: Mesin Machine Notification Breakdown Closed',
            'message' => 'Hai, mesin Machine Notification telah selesai diperbaiki. Terima kasih kepada seluruh tim yang bertugas atas respons cepat dan kerja kerasnya!',
            'target_role' => 'admin',
            'related_table' => 'breakdowns',
            'related_id' => 303,
            'target_url' => '/breakdown/review/303',
        ]);

        $this->actingAs($this->adminA)
            ->postJson('/admin/notifications/'.$notification->id.'/read')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $notification->id,
            'user_id' => $this->adminA->id,
        ]);

        $this->assertDatabaseMissing('notification_reads', [
            'notification_id' => $notification->id,
            'user_id' => $this->adminB->id,
        ]);
    }

    public function test_mark_all_as_read_marks_only_current_admin(): void
    {
        $n1 = PrimeNotification::query()->create([
            'notification_type' => 'pm_overdue',
            'title' => 'Pengingat: Jadwal PM Mesin Machine Notification Melewati Batas Waktu',
            'message' => 'Hai, mengingatkan bahwa jadwal Preventive Maintenance untuk mesin Machine Notification saat ini telah melewati tanggal pengerjaan yang ditentukan. Mohon agar dapat segera ditindaklanjuti.',
            'target_role' => 'admin',
            'related_table' => 'pm_schedule_dates',
            'related_id' => 404,
            'target_url' => '/machines/MC-NTF-01',
        ]);

        $n2 = PrimeNotification::query()->create([
            'notification_type' => 'pm_missed',
            'title' => 'Pembaruan Status: Jadwal PM Mesin Machine Notification Tidak Terlaksana (Missed)',
            'message' => 'Mohon perhatiannya, jadwal Preventive Maintenance untuk mesin Machine Notification kini berstatus Missed karena telah melewati batas waktu pengerjaan. Pastikan operator mengerjakan PM sesuai jadwal yang ditentukan.',
            'target_role' => 'admin',
            'related_table' => 'pm_schedule_dates',
            'related_id' => 405,
            'target_url' => '/machines/MC-NTF-01',
        ]);

        $this->actingAs($this->adminA)
            ->postJson('/admin/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('updated', 2);

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $n1->id,
            'user_id' => $this->adminA->id,
        ]);
        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $n2->id,
            'user_id' => $this->adminA->id,
        ]);

        $this->assertDatabaseMissing('notification_reads', [
            'notification_id' => $n1->id,
            'user_id' => $this->adminB->id,
        ]);
    }

    public function test_create_unique_duplicate_notification_returns_same_notification(): void
    {
        $service = app(AdminNotificationService::class);

        $first = $service->createUnique(
            notificationType: 'breakdown_open',
            title: 'Laporan Breakdown: Mesin Machine Notification Breakdown Open',
            message: 'Hai, breakdown baru tercatat untuk mesin Machine Notification. Mohon bantuan teknisi untuk segera melakukan pengecekan dan perbaikan',
            relatedTable: 'breakdowns',
            relatedId: 901,
            targetUrl: '/breakdown/review/901',
            data: [
                'machine_code' => 'MC-NTF-01',
            ],
        );

        $second = $service->createUnique(
            notificationType: 'breakdown_open',
            title: 'Laporan Breakdown: Mesin Machine Notification Breakdown Open',
            message: 'Hai, breakdown baru tercatat untuk mesin Machine Notification. Mohon bantuan teknisi untuk segera melakukan pengecekan dan perbaikan',
            relatedTable: 'breakdowns',
            relatedId: 901,
            targetUrl: '/breakdown/review/901',
            data: [
                'machine_code' => 'MC-NTF-01',
            ],
        );

        $this->assertSame($first->id, $second->id);
    }

    public function test_pm_sync_command_creates_overdue_and_missed_once_without_duplicate(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'CHK-NTF-01',
            'checksheet_name' => 'Checksheet Notif',
            'is_active' => true,
            'created_by' => $this->adminA->id,
        ]);

        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
            'created_by' => $this->adminA->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'frequency_type' => 'daily',
            'start_date' => now()->subWeek()->toDateString(),
            'generate_until' => now()->addWeek()->toDateString(),
            'is_active' => true,
            'created_by' => $this->adminA->id,
        ]);

        $overdueScheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $missedScheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => now()->subDays(2)->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);
        $this->artisan('pm:sync-schedule-status')->assertExitCode(0);

        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $overdueScheduleDate->id,
            'status' => 'overdue',
        ]);

        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $missedScheduleDate->id,
            'status' => 'missed',
        ]);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_overdue',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $overdueScheduleDate->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'pm_missed',
            'related_table' => 'pm_schedule_dates',
            'related_id' => $missedScheduleDate->id,
        ]);
    }
}
