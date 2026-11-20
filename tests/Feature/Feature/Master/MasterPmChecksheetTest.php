<?php

namespace Tests\Feature\Feature\Master;

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
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\Auth\ActivityLogService;
use App\Services\Master\PmChecksheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MasterPmChecksheetTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected Machine $machine;

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

        $this->machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-01',
            'machine_name' => 'Sealing Machine',
            'qr_token' => 'qr-mc-01',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_access_master_checksheet_pages(): void
    {
        $this->actingAs($this->admin)->get('/pm/master-checksheet')->assertOk()->assertSee('Master PM Checksheet');
        $this->actingAs($this->admin)->get('/pm/master-checksheet/create')->assertOk()->assertSee('Buat Checksheet Baru');
    }

    public function test_only_admin_can_access_master_checksheet_pages(): void
    {
        $guestSession = GuestSession::query()->create([
            'guest_name' => 'Guest PRIME',
            'session_id' => 'guest-session',
            'login_at' => now(),
        ]);

        $this->actingAs($this->operator)->get('/pm/master-checksheet')->assertRedirect('/403');

        $this->withSession([
            'guest_session_id' => $guestSession->id,
            'guest_name' => $guestSession->guest_name,
        ])->get('/pm/master-checksheet')->assertRedirect('/403');
    }

    public function test_checksheet_status_and_audit_roll_back_together(): void
    {
        foreach ([true, false] as $initialStatus) {
            $checksheet = PmChecksheet::query()->create([
                'checksheet_code' => $initialStatus ? 'PM-AUDIT-ACTIVE' : 'PM-AUDIT-INACTIVE',
                'checksheet_name' => 'Status dengan audit atomik',
                'is_active' => $initialStatus,
            ]);
            $this->mock(ActivityLogService::class, function ($mock): void {
                $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
            });
            $service = app(PmChecksheetService::class);
            $this->assertThrows(
                fn () => $initialStatus
                    ? $service->deactivate(request(), $checksheet)
                    : $service->activate(request(), $checksheet),
                \RuntimeException::class,
            );

            $this->assertSame($initialStatus, $checksheet->fresh()->is_active);
            $this->assertSame(0, DB::table('user_activity_logs')
                ->where('module_name', 'master_checksheet')
                ->where('record_id', $checksheet->id)
                ->count());
        }
    }

    public function test_admin_can_create_checksheet_with_nested_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $payload = [
            'selected_machine_ids' => [$this->machine->id],
            'parts' => [
                $this->machine->id => [
                    ['id' => 'part-1', 'name' => 'Motor Drive', 'description' => 'Part utama'],
                ],
            ],
            'standards' => [
                'part-1' => [
                    [
                        'name' => 'Cek suhu motor',
                        'input_type' => 'number',
                        'target_value' => 60,
                        'unit' => 'Celcius',
                        'is_required' => true,
                        'is_active' => true,
                    ],
                ],
            ],
            'schedule' => [
                'frequency_type' => 'daily',
                'operational_from' => '2026-05-21',
                'weekly_days' => [],
                'monthly_day' => null,
            ],
        ];

        $response = $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-001',
            'checksheet_name' => 'PM Mingguan Line 1',
            'description' => 'Checksheet test',
            'is_active' => '1',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-001')->firstOrFail();

        $response->assertRedirect("/pm/master-checksheet/{$checksheet->id}");
        $this->assertDatabaseHas('pm_checksheets', ['checksheet_code' => 'PM-CH-001']);
        $this->assertDatabaseHas('pm_checksheet_machines', ['pm_checksheet_id' => $checksheet->id, 'machine_id' => $this->machine->id]);
        $this->assertDatabaseHas('pm_checksheet_parts', ['part_name' => 'Motor Drive']);
        $this->assertDatabaseHas('pm_checksheet_standards', ['standard_name' => 'Cek suhu motor', 'input_type' => 'number']);
        $this->assertDatabaseHas('pm_schedules', ['frequency_type' => 'daily']);
        $this->assertDatabaseHas('pm_schedule_dates', ['scheduled_date' => '2026-05-21 00:00:00']);
        $this->assertDatabaseHas('user_activity_logs', ['module_name' => 'master_checksheet', 'action' => 'create']);
    }

    public function test_admin_can_update_and_delete_checksheet(): void
    {
        $this->test_admin_can_create_checksheet_with_nested_data();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-001')->firstOrFail();

        $payload = [
            'selected_machine_ids' => [$this->machine->id],
            'parts' => [
                $this->machine->id => [
                    ['id' => 'part-x', 'name' => 'Heater Block', 'description' => 'Part update'],
                ],
            ],
            'standards' => [
                'part-x' => [
                    [
                        'name' => 'Cek tekanan',
                        'input_type' => 'range',
                        'min_value' => 10,
                        'max_value' => 20,
                        'unit' => 'Bar',
                        'is_required' => true,
                        'is_active' => true,
                    ],
                ],
            ],
            'schedule' => [
                'frequency_type' => 'weekly',
                'start_date' => '2026-05-21',
                'generate_until' => '2026-06-21',
                'weekly_days' => [1, 4],
                'monthly_day' => null,
            ],
        ];

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-001',
            'checksheet_name' => 'PM Update',
            'description' => 'Updated',
            'is_active' => '1',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}");

        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id, 'checksheet_name' => 'PM Update']);
        $this->assertDatabaseHas('pm_checksheet_parts', ['part_name' => 'Heater Block']);
        $this->assertDatabaseHas('pm_schedules', ['frequency_type' => 'weekly']);
        $this->assertDatabaseHas('user_activity_logs', ['module_name' => 'master_checksheet', 'action' => 'update']);

        $this->actingAs($this->admin)->patch("/pm/master-checksheet/{$checksheet->id}/nonaktifkan")->assertRedirect('/pm/master-checksheet');
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id, 'is_active' => false]);

        // Checksheet ini sudah pernah terhubung ke mesin, sehingga tidak boleh dihapus
        // meskipun sudah dinonaktifkan. Guard delete menolak dan mengembalikan flash_error.
        $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$checksheet->id}")
            ->assertRedirect('/pm/master-checksheet')
            ->assertSessionHas('flash_error');
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id]);
        $this->assertDatabaseMissing('user_activity_logs', ['module_name' => 'master_checksheet', 'action' => 'delete']);
    }

    public function test_updating_checksheet_keeps_existing_pm_review_data(): void
    {
        $this->test_admin_can_create_checksheet_with_nested_data();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-001')->firstOrFail();
        $scheduleDate = PmScheduleDate::query()->firstOrFail();
        $historicalScheduleDate = PmScheduleDate::query()->whereKeyNot($scheduleDate->id)->firstOrFail();
        $unworkedScheduleDate = PmScheduleDate::query()
            ->whereKeyNot([$scheduleDate->id, $historicalScheduleDate->id])
            ->firstOrFail();

        // Create kini menghasilkan jendela penuh 12 bulan (hingga 2027-05-21),
        // sehingga tanggal Agustus 2026 sudah ter-generate dan belum dikerjakan.
        // Bersihkan dahulu agar skenario di bawah (jadwal harian 1-3 Agustus)
        // tidak bentrok dengan baris aktif bawaan hasil create.
        PmScheduleDate::query()
            ->where('pm_schedule_id', $scheduleDate->pm_schedule_id)
            ->whereBetween('scheduled_date', ['2026-08-01 00:00:00', '2026-08-31 23:59:59'])
            ->forceDelete();

        $scheduleDate->forceFill(['status' => 'waiting_review'])->save();

        // Identitas transaksi bersifat atomik: halaman review hanya menampilkan
        // bundle snapshot lengkap (ADR-007 Slice A), sehingga execution ini
        // membawa snapshot seperti hasil Mulai PM yang nyata.
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'machine_code_snapshot' => $this->machine->machine_code,
            'machine_name_snapshot' => $this->machine->machine_name,
            'location_code_snapshot' => 'LOC-01',
            'location_name_snapshot' => 'Line 1',
            'checksheet_code_snapshot' => 'PM-CH-001',
            'checksheet_name_snapshot' => 'PM Mingguan Line 1',
            'status' => 'waiting_review',
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
        ]);

        PmExecution::query()->create([
            'pm_schedule_date_id' => $historicalScheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
        ])->delete();

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $scheduleDate->pm_schedule_id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-08-01',
            'status' => 'scheduled',
            'generated_at' => now(),
        ])->delete();

        PmSchedule::query()->whereKey($scheduleDate->pm_schedule_id)->update([
            'frequency_type' => 'daily',
            'weekly_days' => null,
            'monthly_day' => null,
            'start_date' => '2026-08-01',
            'generate_until' => '2026-08-03',
        ]);

        $payload = [
            'selected_machine_ids' => [$this->machine->id],
            'parts' => [
                $this->machine->id => [
                    ['id' => 'part-1', 'name' => 'Motor Drive', 'description' => 'Part tetap'],
                ],
            ],
            'standards' => [
                'part-1' => [
                    [
                        'name' => 'Cek suhu motor',
                        'input_type' => 'number',
                        'target_value' => 65,
                        'unit' => 'Celcius',
                        'is_required' => true,
                        'is_active' => true,
                    ],
                ],
            ],
            'schedule' => [
                'frequency_type' => 'daily',
                'start_date' => '2026-08-01',
                'generate_until' => '2026-08-03',
                'weekly_days' => [],
                'monthly_day' => null,
            ],
        ];

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-001',
            'checksheet_name' => 'PM Mingguan Line 1 Revisi',
            'description' => 'Updated',
            'is_active' => '1',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}");

        $this->assertDatabaseHas('pm_executions', ['id' => $execution->id, 'status' => 'waiting_review']);
        $this->assertDatabaseHas('pm_schedule_dates', ['id' => $scheduleDate->id, 'status' => 'waiting_review']);
        $this->assertDatabaseHas('pm_schedule_dates', ['id' => $historicalScheduleDate->id]);
        // Baris belum dikerjakan ini berada sebelum floor materialisasi jadwal
        // baru (start_date 2026-08-01), sehingga reconciliation mempertahankan
        // occurrence historis apa adanya dan tidak menghapusnya.
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $unworkedScheduleDate->id,
            'scheduled_date' => $unworkedScheduleDate->scheduled_date->toDateTimeString(),
            'status' => 'scheduled',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('pm_schedule_dates', ['scheduled_date' => '2026-08-01 00:00:00', 'status' => 'scheduled', 'deleted_at' => null]);
        $this->assertDatabaseHas('pm_schedule_dates', ['scheduled_date' => '2026-08-03 00:00:00', 'status' => 'scheduled']);

        $scheduleDateRowsAfterFirstSave = PmScheduleDate::query()
            ->withTrashed()
            ->where('pm_schedule_id', $scheduleDate->pm_schedule_id)
            ->orderBy('id')
            ->get(['id', 'scheduled_date', 'status', 'deleted_at'])
            ->map(fn (PmScheduleDate $date) => [
                $date->id,
                $date->scheduled_date->toDateString(),
                $date->status,
                $date->deleted_at?->toDateTimeString(),
            ])
            ->all();

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-001',
            'checksheet_name' => 'PM Mingguan Line 1 Revisi',
            'description' => 'Updated',
            'is_active' => '1',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}");

        $this->assertSame($scheduleDateRowsAfterFirstSave, PmScheduleDate::query()
            ->withTrashed()
            ->where('pm_schedule_id', $scheduleDate->pm_schedule_id)
            ->orderBy('id')
            ->get(['id', 'scheduled_date', 'status', 'deleted_at'])
            ->map(fn (PmScheduleDate $date) => [
                $date->id,
                $date->scheduled_date->toDateString(),
                $date->status,
                $date->deleted_at?->toDateTimeString(),
            ])
            ->all());
        $this->actingAs($this->admin)->get('/pm/review')->assertOk()->assertSee('MC-01');
    }

    /**
     * Memastikan lock Machine terjadi sebelum mutation child/config checksheet.
     */
    public function test_update_locks_selected_machines_before_child_mutation(): void
    {
        // Kunci tanggal bisnis agar fixture operational_from 2026-09-15 tetap
        // berada di masa depan; test tidak boleh bergantung pada tanggal berjalan.
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:00:00', 'Asia/Jakarta'));

        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-LOCK-ORDER',
            'checksheet_name' => 'Lock Order Checksheet',
            'is_active' => true,
        ]);

        PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
        ]);

        $queries = [];
        // Hook ini mengamati urutan query tanpa bergantung pada SQL FOR UPDATE SQLite.
        DB::connection()->beforeExecuting(function (string $query) use (&$queries): void {
            $queries[] = strtolower($query);
        });
        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-LOCK-ORDER',
            'checksheet_name' => 'Lock Order Checksheet',
            'is_active' => '1',
            'wizard_payload' => json_encode([
                'selected_machine_ids' => [$this->machine->id],
                'parts' => [
                    $this->machine->id => [
                        ['id' => 'part-lock', 'name' => 'Part Lock', 'description' => 'Part test'],
                    ],
                ],
                'standards' => [
                    'part-lock' => [[
                        'name' => 'Standard Lock',
                        'input_type' => 'number',
                        'target_value' => 1,
                        'action_options' => [],
                        'is_required' => true,
                        'is_active' => true,
                    ]],
                ],
                'schedule' => [
                    'frequency_type' => 'weekly',
                    'weekly_days' => [1],
                    'monthly_day' => null,
                    'operational_from' => '2026-09-15',
                ],
            ], JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}");

        $machineLock = array_key_first(array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'from "machines"')
                && str_contains($query, 'where "id" in')
                && str_contains($query, 'order by "id" asc'),
        ));
        $childMutation = array_key_first(array_filter(
            $queries,
            fn (string $query): bool => preg_match(
                '/\b(insert into|update)\s+"?pm_(checksheet_machines|checksheet_parts|checksheet_standards|schedules)/i',
                $query,
            ) === 1,
        ));

        $this->assertNotNull($machineLock, 'Selected machines must be locked before synchronization.');
        $this->assertNotNull($childMutation, 'Synchronization must mutate child/config rows.');
        $this->assertLessThan($childMutation, $machineLock);
    }

    // =========================================================
    // SLICE 4 — PM Checksheet Historical Delete Protection
    // =========================================================

    // ---------------------------------------------------------
    // A. Migration / default semantics
    // ---------------------------------------------------------

    /**
     * A: Migrated-style rows (created via raw DB) must default to
     * assignment_history_known=false and first_observed_machine_assignment_at=NULL.
     */
    public function test_s4_a_legacy_row_defaults_history_known_false_and_no_observed_timestamp(): void
    {
        // Insert a row bypassing the application service (simulates legacy/migrated row)
        $id = DB::table('pm_checksheets')->insertGetId([
            'checksheet_code' => 'LEGACY-DEF',
            'checksheet_name' => 'Legacy Default Row',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checksheet = PmChecksheet::query()->findOrFail($id);

        $this->assertFalse(
            (bool) $checksheet->assignment_history_known,
            'Raw/migrated row must default to assignment_history_known=false'
        );
        $this->assertNull(
            $checksheet->first_observed_machine_assignment_at,
            'Raw/migrated row must default to first_observed_machine_assignment_at=NULL'
        );
    }

    /**
     * A: Checksheet created through the normal application service must have
     * assignment_history_known=true (explicitly set by service, not relying on default).
     */
    public function test_s4_a_new_application_checksheet_is_history_known(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-NEW-KNOWN',
            'checksheet_name' => 'New Known Checksheet',
            'is_active' => '1',
            'wizard_payload' => json_encode([
                'selected_machine_ids' => [$this->machine->id],
                'parts' => [
                    $this->machine->id => [
                        ['id' => 'part-k', 'name' => 'Motor K', 'description' => ''],
                    ],
                ],
                'standards' => [
                    'part-k' => [[
                        'name' => 'Cek suhu K',
                        'input_type' => 'number',
                        'target_value' => 60,
                        'action_options' => [],
                        'unit' => 'C',
                        'is_required' => true,
                        'is_active' => true,
                    ]],
                ],
                'schedule' => [
                    'frequency_type' => 'daily',
                    'operational_from' => '2026-05-21',
                    'weekly_days' => [],
                    'monthly_day' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-NEW-KNOWN')->firstOrFail();

        $this->assertTrue(
            (bool) $checksheet->assignment_history_known,
            'Application-created checksheet must have assignment_history_known=true'
        );
        $this->assertNotNull(
            $checksheet->first_observed_machine_assignment_at,
            'Application-created checksheet with a machine assignment must have first_observed_machine_assignment_at set'
        );
    }

    // ---------------------------------------------------------
    // B. Pristine hard-delete (NEW PRISTINE state)
    // ---------------------------------------------------------

    /**
     * B: Known-history checksheet with no assignment and no observed timestamp
     * must be permanently deletable by admin.
     */
    public function test_s4_b_pristine_checksheet_can_be_hard_deleted(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-PRISTINE',
            'checksheet_name' => 'Pristine Checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$checksheet->id}")
            ->assertRedirect('/pm/master-checksheet');

        $this->assertDatabaseMissing('pm_checksheets', ['id' => $checksheet->id, 'deleted_at' => null]);
        // forceDelete means the row is physically absent
        $this->assertNull(PmChecksheet::withTrashed()->find($checksheet->id));
    }

    // ---------------------------------------------------------
    // C. Legacy zero-assignment rejection
    // ---------------------------------------------------------

    /**
     * C: Legacy checksheet (assignment_history_known=false) with zero current
     * assignments must be rejected with the legacy ambiguous copy.
     */
    public function test_s4_c_legacy_zero_assignment_delete_rejected(): void
    {
        // Raw insert: assignment_history_known defaults false
        $id = DB::table('pm_checksheets')->insertGetId([
            'checksheet_code' => 'LEGACY-ZERO',
            'checksheet_name' => 'Legacy Zero Assignment',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$id}");

        $response->assertRedirect();
        $this->assertStringContainsString(
            'Data operasional lama tidak dapat dihapus permanen',
            session('flash_error') ?? '',
            'Legacy ambiguous rejection must return the expected business copy'
        );

        // Checksheet must still exist
        $this->assertDatabaseHas('pm_checksheets', ['id' => $id]);
    }

    // ---------------------------------------------------------
    // D. Current assignment blocks delete
    // ---------------------------------------------------------

    /**
     * D: Checksheet with any current machine assignment must be rejected with
     * the known-assignment copy, regardless of schedule/occurrence state.
     */
    public function test_s4_d_current_assignment_blocks_delete(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-ASSIGNED',
            'checksheet_name' => 'Assigned Checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
        ]);

        PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$checksheet->id}");

        $response->assertRedirect();
        $this->assertStringContainsString(
            'PM Checksheet yang sudah terhubung ke mesin tidak dapat dihapus',
            session('flash_error') ?? '',
            'Known assignment rejection must return the expected business copy'
        );

        // Checksheet and assignment must still exist
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id]);
        $this->assertDatabaseHas('pm_checksheet_machines', [
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
        ]);
    }

    // ---------------------------------------------------------
    // E. First assignment provenance (marker stamped atomically)
    // ---------------------------------------------------------

    /**
     * E: Creating a genuinely new Machine assignment on a known-pristine
     * checksheet must populate first_observed_machine_assignment_at exactly once.
     */
    public function test_s4_e_first_assignment_stamps_observed_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-PROVENANCE',
            'checksheet_name' => 'Provenance Checksheet',
            'is_active' => '1',
            'wizard_payload' => json_encode([
                'selected_machine_ids' => [$this->machine->id],
                'parts' => [
                    $this->machine->id => [
                        ['id' => 'part-prov', 'name' => 'Motor', 'description' => ''],
                    ],
                ],
                'standards' => [
                    'part-prov' => [[
                        'name' => 'Cek suhu',
                        'input_type' => 'number',
                        'target_value' => 60,
                        'action_options' => [],
                        'unit' => 'C',
                        'is_required' => true,
                        'is_active' => true,
                    ]],
                ],
                'schedule' => [
                    'frequency_type' => 'daily',
                    'operational_from' => '2026-05-21',
                    'weekly_days' => [],
                    'monthly_day' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-PROVENANCE')->firstOrFail();

        $this->assertTrue(
            (bool) $checksheet->assignment_history_known,
            'Newly created checksheet must remain history_known=true'
        );
        $this->assertNotNull(
            $checksheet->first_observed_machine_assignment_at,
            'First assignment must populate first_observed_machine_assignment_at'
        );
    }

    // ---------------------------------------------------------
    // F. Marker immutability (subsequent assignment update)
    // ---------------------------------------------------------

    /**
     * F: Re-saving the checksheet (update) or adding a second assignment must NOT
     * overwrite the original first_observed_machine_assignment_at timestamp.
     */
    public function test_s4_f_marker_immutable_on_subsequent_update(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        // Create with first assignment
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-IMMUTABLE',
            'checksheet_name' => 'Immutable Checksheet',
            'is_active' => '1',
            'wizard_payload' => json_encode([
                'selected_machine_ids' => [$this->machine->id],
                'parts' => [$this->machine->id => [['id' => 'p1', 'name' => 'PartA', 'description' => '']]],
                'standards' => ['p1' => [['name' => 'S1', 'input_type' => 'number', 'target_value' => 1, 'action_options' => [], 'unit' => null, 'is_required' => true, 'is_active' => true]]],
                'schedule' => ['frequency_type' => 'daily', 'operational_from' => '2026-05-21', 'weekly_days' => [], 'monthly_day' => null],
            ], JSON_THROW_ON_ERROR),
        ]);

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-IMMUTABLE')->firstOrFail();
        $originalTimestamp = $checksheet->first_observed_machine_assignment_at;
        $this->assertNotNull($originalTimestamp);

        // Advance time and create a second machine
        $location2 = Location::query()->create(['location_code' => 'LOC-F2', 'location_name' => 'Line F2', 'is_active' => true]);
        $machine2 = Machine::query()->create([
            'location_id' => $location2->id,
            'machine_code' => 'MC-F2',
            'machine_name' => 'Machine F2',
            'qr_token' => 'qr-mc-f2',
            'is_active' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-01 00:00:00', 'Asia/Jakarta'));

        // Update to include a second machine
        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-IMMUTABLE',
            'checksheet_name' => 'Immutable Checksheet',
            'is_active' => '1',
            'wizard_payload' => json_encode([
                'selected_machine_ids' => [$this->machine->id, $machine2->id],
                'parts' => [
                    $this->machine->id => [['id' => 'p1', 'name' => 'PartA', 'description' => '']],
                    $machine2->id => [['id' => 'p2', 'name' => 'PartB', 'description' => '']],
                ],
                'standards' => [
                    'p1' => [['name' => 'S1', 'input_type' => 'number', 'target_value' => 1, 'action_options' => [], 'unit' => null, 'is_required' => true, 'is_active' => true]],
                    'p2' => [['name' => 'S2', 'input_type' => 'number', 'target_value' => 2, 'action_options' => [], 'unit' => null, 'is_required' => true, 'is_active' => true]],
                ],
                'schedule' => ['frequency_type' => 'daily', 'operational_from' => '2026-06-01', 'weekly_days' => [], 'monthly_day' => null],
            ], JSON_THROW_ON_ERROR),
        ]);

        $checksheet->refresh();
        $this->assertEquals(
            $originalTimestamp->toDateTimeString(),
            $checksheet->first_observed_machine_assignment_at->toDateTimeString(),
            'Original first_observed_machine_assignment_at must not be overwritten on subsequent update'
        );
    }

    // ---------------------------------------------------------
    // G. Legacy remains ambiguous after new assignment
    // ---------------------------------------------------------

    /**
     * G: A legacy checksheet (assignment_history_known=false) must NOT become
     * pristine even after receiving a new post-instrumentation assignment.
     * After removing the current assignment, the delete must still be rejected
     * with the legacy-specific copy — proving Guard 2 fires, not Guard 1.
     */
    public function test_s4_g_legacy_checksheet_remains_ambiguous_after_new_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        // Create as legacy row: assignment_history_known = false (raw insert)
        $id = DB::table('pm_checksheets')->insertGetId([
            'checksheet_code' => 'PM-LEGACY-NEW-ASSIGN',
            'checksheet_name' => 'Legacy Gets New Assignment',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $checksheet = PmChecksheet::query()->findOrFail($id);

        // Update through the application service — adds assignment to this legacy checksheet
        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-LEGACY-NEW-ASSIGN',
            'checksheet_name' => 'Legacy Gets New Assignment',
            'is_active' => '1',
            'wizard_payload' => json_encode([
                'selected_machine_ids' => [$this->machine->id],
                'parts' => [$this->machine->id => [['id' => 'pg', 'name' => 'PartG', 'description' => '']]],
                'standards' => ['pg' => [['name' => 'SG', 'input_type' => 'number', 'target_value' => 1, 'action_options' => [], 'unit' => null, 'is_required' => true, 'is_active' => true]]],
                'schedule' => ['frequency_type' => 'daily', 'operational_from' => '2026-05-21', 'weekly_days' => [], 'monthly_day' => null],
            ], JSON_THROW_ON_ERROR),
        ]);

        $checksheet->refresh();

        $this->assertFalse(
            (bool) $checksheet->assignment_history_known,
            'Legacy checksheet must never become assignment_history_known=true'
        );

        // Spec: legacy row receives first_observed_machine_assignment_at when an instrumented
        // new assignment is created, but remains non-deletable via Guard 2.
        $this->assertNotNull(
            $checksheet->first_observed_machine_assignment_at,
            'Legacy checksheet should receive marker timestamp on new instrumented assignment'
        );

        // Remove the current assignment so Guard 1 does NOT fire —
        // we want to prove Guard 2 rejects, not Guard 1.
        PmChecksheetMachine::query()
            ->where('pm_checksheet_id', $checksheet->id)
            ->delete();

        $this->assertCount(
            0,
            $checksheet->machineAssignments()->get(),
            'Assignment must be removed before testing Guard 2'
        );

        // Delete must be rejected by Guard 2 (legacy ambiguity), not Guard 1.
        $response = $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$checksheet->id}");
        $response->assertRedirect();
        $this->assertStringContainsString(
            'Data operasional lama tidak dapat dihapus permanen',
            session('flash_error') ?? '',
            'Guard 2 must reject legacy row with the legacy-specific business copy'
        );
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id]);
    }

    // ---------------------------------------------------------
    // H. Rollback atomicity
    // ---------------------------------------------------------

    /**
     * H: If an exception occurs during the assignment + marker path, both the
     * assignment row and the marker update must roll back atomically.
     *
     * Strategy: use a beforeExecuting hook to throw after the marker UPDATE
     * SQL is detected but before the transaction commits. We assert the hook
     * actually fired so the test cannot pass silently without exercising the
     * atomicity boundary.
     */
    public function test_s4_h_assignment_and_marker_roll_back_together(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        // Create pristine checksheet with no assignments
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-ROLLBACK',
            'checksheet_name' => 'Rollback Test Checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
        ]);

        $markerUpdateSeen = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$markerUpdateSeen): void {
            if (! $markerUpdateSeen
                && str_contains(strtolower($sql), 'update')
                && str_contains(strtolower($sql), 'pm_checksheets')
                && str_contains(strtolower($sql), 'first_observed_machine_assignment_at')
            ) {
                $markerUpdateSeen = true;
                throw new \RuntimeException('Controlled rollback injection for test_s4_h');
            }
        });

        try {
            $this->actingAs($this->admin)->post('/pm/master-checksheet', [
                'checksheet_code' => 'PM-ROLLBACK-ASSIGN',
                'checksheet_name' => 'Rollback Assign Attempt',
                'is_active' => '1',
                'wizard_payload' => json_encode([
                    'selected_machine_ids' => [$this->machine->id],
                    'parts' => [$this->machine->id => [['id' => 'ph', 'name' => 'PartH', 'description' => '']]],
                    'standards' => ['ph' => [['name' => 'SH', 'input_type' => 'number', 'target_value' => 1, 'action_options' => [], 'unit' => null, 'is_required' => true, 'is_active' => true]]],
                    'schedule' => ['frequency_type' => 'daily', 'operational_from' => '2026-05-21', 'weekly_days' => [], 'monthly_day' => null],
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (\Throwable) {
            // Exception is expected due to the rollback injection
        }

        // The hook MUST have fired — otherwise the test proves nothing about atomicity.
        $this->assertTrue($markerUpdateSeen, 'beforeExecuting hook must fire at the marker UPDATE');

        // Because the hook threw inside the outer DB::transaction wrapping create(),
        // the whole transaction rolls back: the new checksheet row must be absent.
        $this->assertNull(
            PmChecksheet::withTrashed()->where('checksheet_code', 'PM-ROLLBACK-ASSIGN')->first(),
            'Rolled-back checksheet row must be absent; transaction did not commit'
        );

        // The original checksheet must be unchanged
        $this->assertDatabaseHas('pm_checksheets', [
            'id' => $checksheet->id,
            'checksheet_code' => 'PM-ROLLBACK',
        ]);
    }

    // ---------------------------------------------------------
    // I. Protected descendants survive rejection
    // ---------------------------------------------------------

    /**
     * I: Checksheet with protected descendants (execution in waiting_review)
     * must be rejected; all descendants must survive intact.
     */
    public function test_s4_i_protected_descendants_survive_delete_rejection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $this->test_admin_can_create_checksheet_with_nested_data();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-001')->firstOrFail();
        $scheduleDate = PmScheduleDate::query()->firstOrFail();
        $scheduleDate->forceFill(['status' => 'waiting_review'])->save();

        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDate->id,
            'machine_id' => $this->machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => 'waiting_review',
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$checksheet->id}");

        $response->assertRedirect();

        // All protected descendants must survive
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id]);
        $this->assertDatabaseHas('pm_executions', ['id' => $execution->id, 'status' => 'waiting_review']);
        $this->assertDatabaseHas('pm_schedule_dates', ['id' => $scheduleDate->id, 'status' => 'waiting_review']);
    }

    // ---------------------------------------------------------
    // J. Lifecycle regression: deactivate / activate / update
    // ---------------------------------------------------------

    /**
     * J: Deactivate, activate, and update must remain functional
     * after the delete guard is added. This proves the guard does not
     * break other lifecycle operations.
     */
    public function test_s4_j_lifecycle_operations_remain_functional(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-LIFECYCLE',
            'checksheet_name' => 'Lifecycle Checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
        ]);

        // Deactivate
        $this->actingAs($this->admin)
            ->patch("/pm/master-checksheet/{$checksheet->id}/nonaktifkan")
            ->assertRedirect('/pm/master-checksheet');
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id, 'is_active' => false]);

        // Activate
        $this->actingAs($this->admin)
            ->patch("/pm/master-checksheet/{$checksheet->id}/aktifkan")
            ->assertRedirect('/pm/master-checksheet');
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id, 'is_active' => true]);

        // Update metadata
        // Update metadata — requires a valid wizard payload per validation rules
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-LIFECYCLE',
            'checksheet_name' => 'Lifecycle Updated',
            'is_active' => '1',
            'wizard_payload' => json_encode([
                'selected_machine_ids' => [$this->machine->id],
                'parts' => [$this->machine->id => [['id' => 'pj', 'name' => 'PartJ', 'description' => '']]],
                'standards' => ['pj' => [['name' => 'SJ', 'input_type' => 'number', 'target_value' => 1, 'action_options' => [], 'unit' => null, 'is_required' => true, 'is_active' => true]]],
                'schedule' => ['frequency_type' => 'daily', 'operational_from' => '2026-05-21', 'weekly_days' => [], 'monthly_day' => null],
            ], JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}");
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id, 'checksheet_name' => 'Lifecycle Updated']);
    }
    // ---------------------------------------------------------

    /**
     * K: Non-admin (operator) delete attempt must be rejected by existing
     * authorization middleware, unchanged by the new delete guard.
     */
    public function test_s4_k_non_admin_delete_is_rejected_by_authorization(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-AUTHZ',
            'checksheet_name' => 'Authorization Checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
        ]);

        $this->actingAs($this->operator)
            ->delete("/pm/master-checksheet/{$checksheet->id}")
            ->assertRedirect('/403');

        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id]);
    }

    // ---------------------------------------------------------
    // L. Repeated rejection is idempotent / zero-destructive
    // ---------------------------------------------------------

    /**
     * L: Two repeated delete attempts on a protected checksheet must both be
     * rejected with zero mutation and no successful-delete activity log entry.
     */
    public function test_s4_l_repeated_delete_rejection_is_zero_destructive(): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-REPEAT',
            'checksheet_name' => 'Repeated Rejection Checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
        ]);

        PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
        ]);

        // Capture all columns before any delete attempt
        $before = PmChecksheet::query()->findOrFail($checksheet->id)->toArray();

        // First attempt
        $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$checksheet->id}")
            ->assertRedirect();
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id]);

        // Second attempt
        $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$checksheet->id}")
            ->assertRedirect();
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id]);

        // Row state must be identical after both rejections
        $after = PmChecksheet::query()->findOrFail($checksheet->id)->toArray();
        $this->assertSame(
            $before['checksheet_code'],
            $after['checksheet_code'],
            'Checksheet code must be unchanged after repeated rejection'
        );
        $this->assertSame(
            $before['is_active'],
            $after['is_active'],
            'is_active must be unchanged after repeated rejection'
        );

        // No successful-delete activity log
        $this->assertDatabaseMissing('user_activity_logs', [
            'module_name' => 'master_checksheet',
            'action' => 'delete',
            'record_id' => $checksheet->id,
        ]);
    }

    // ---------------------------------------------------------
    // M. Guard 3 isolated — formerly-assigned with no current assignment
    // ---------------------------------------------------------

    /**
     * M: A checksheet with assignment_history_known=true and
     * first_observed_machine_assignment_at set, but zero current machine
     * assignments, must be rejected by Guard 3 with the known-assignment copy.
     *
     * This isolates Guard 3 from Guards 1 and 2 so that removing Guard 3
     * would cause this test to fail.
     */
    public function test_s4_m_formerly_assigned_checksheet_rejected_by_guard3(): void
    {
        // Pristine but with marker set — simulates a checksheet whose
        // assignment was deleted outside the application service layer
        // (e.g. test teardown, direct DB cleanup) while marker is preserved.
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-GUARD3',
            'checksheet_name' => 'Guard 3 Isolation Checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
            'first_observed_machine_assignment_at' => now()->subDay(),
        ]);

        // Explicitly confirm: no current assignment exists (Guard 1 must not fire).
        $this->assertCount(0, $checksheet->machineAssignments()->get(), 'Fixture must have zero assignments');

        $response = $this->actingAs($this->admin)
            ->delete("/pm/master-checksheet/{$checksheet->id}");

        $response->assertRedirect();
        $this->assertStringContainsString(
            'PM Checksheet yang sudah terhubung ke mesin tidak dapat dihapus',
            session('flash_error') ?? '',
            'Guard 3 must reject formerly-assigned checksheet with the known-assignment copy'
        );
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id]);
    }

    /**
     * Regresi: toWizardPayload() harus mengeluarkan standards sebagai JSON object ("{}"),
     * bukan JSON array ("[]"), ketika checksheet belum memiliki part/standard.
     *
     * Akar masalah: $standards = [] di PHP di-encode menjadi "[]" oleh json_encode,
     * sehingga JS menerima Array. Assignment key dinamis (partId) pada Array
     * diabaikan oleh JSON.stringify, dan payload standards ke server selalu kosong.
     *
     * Dua sub-kasus:
     *  (a) Checksheet tanpa part → standards harus "{}" (stdClass/object JSON).
     *  (b) Checksheet dengan part dan standard → standards harus berupa object
     *      dengan key string partId (bukan numerik) dan value array standard.
     */
    public function test_to_wizard_payload_standards_is_json_object_for_empty_and_non_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));

        // ── (a) Checksheet tanpa part ─────────────────────────────────────────
        $checksheetEmpty = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-REGRESSION-EMPTY',
            'checksheet_name' => 'Regression Empty Standards',
            'is_active' => true,
        ]);

        PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheetEmpty->id,
            'machine_id' => $this->machine->id,
        ]);

        $checksheetEmpty->load('machineAssignments.parts.standards');
        /** @var PmChecksheetService $service */
        $service = $this->app->make(PmChecksheetService::class);

        $payloadEmpty = $service->toWizardPayload($checksheetEmpty);
        $encodedEmpty = json_encode($payloadEmpty['standards'], JSON_THROW_ON_ERROR);

        // Harus "{}" bukan "[]" — JS hanya menerima Object dengan aman.
        $this->assertSame('{}', $encodedEmpty, 'standards kosong harus dikodekan sebagai object JSON');
        $this->assertIsObject($payloadEmpty['standards'], 'standards kosong harus berupa stdClass, bukan array PHP');

        // ── (b) Checksheet dengan part dan standard ───────────────────────────
        $this->test_admin_can_create_checksheet_with_nested_data(); // menanam PM-CH-001
        $checksheetFull = PmChecksheet::query()
            ->where('checksheet_code', 'PM-CH-001')
            ->with('machineAssignments.parts.standards')
            ->firstOrFail();

        $payloadFull = $service->toWizardPayload($checksheetFull);
        $encodedFull = json_encode($payloadFull['standards'], JSON_THROW_ON_ERROR);
        $decoded = json_decode($encodedFull, false, 512, JSON_THROW_ON_ERROR);

        // Harus berupa object JSON dengan key string partId.
        $this->assertInstanceOf(\stdClass::class, $decoded, 'standards berisi entry harus dikodekan sebagai JSON object');

        // Key harus berupa string partId (bukan indeks numerik) dan value-nya array standard.
        $keys = array_keys((array) $decoded);
        $this->assertNotEmpty($keys, 'standards object harus memiliki minimal satu key partId');
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^\d+$/', (string) $key, "key partId harus berupa string numerik, dapat: {$key}");
            $this->assertIsArray((array) $decoded->{$key}, "value standards[{$key}] harus berupa array");
        }
    }

    /**
     * Halaman detail checksheet harus menampilkan label Master Action dari pivot
     * bila ada, dan jatuh ke proyeksi legacy action_options bila pivot kosong.
     */
    public function test_checksheet_detail_displays_pivot_actions_and_legacy_fallback(): void
    {
        $action = PmAction::query()->create(['action_label' => 'PIVOT ACTION', 'is_active' => true]);
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'PM-CH-ACT-SHOW',
            'checksheet_name' => 'Action Detail',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $this->machine->id,
            'created_by' => $this->admin->id,
        ]);
        $part = PmChecksheetPart::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'part_name' => 'Action Part',
            'is_active' => true,
        ]);
        $standard = PmChecksheetStandard::query()->create([
            'pm_checksheet_part_id' => $part->id,
            'standard_name' => 'Pivot Standard',
            'input_type' => 'action',
            'action_options' => ['LEGACY ACTION'],
            'is_required' => true,
            'is_active' => true,
        ]);
        $standard->actions()->sync([$action->id => ['position' => 1]]);

        $this->actingAs($this->admin)
            ->get("/pm/master-checksheet/{$checksheet->id}")
            ->assertOk()
            ->assertSee('PIVOT ACTION')
            ->assertDontSee('LEGACY ACTION');

        $standard->actions()->detach();

        $this->actingAs($this->admin)
            ->get("/pm/master-checksheet/{$checksheet->id}")
            ->assertOk()
            ->assertSee('LEGACY ACTION');
    }

    public function test_admin_can_pause_and_resume_a_schedule_from_its_checksheet(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        [$checksheet, $schedule] = $this->createLifecycleSchedule('PM-LIFECYCLE-HTTP');
        $scheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $this->machine->id,
            'scheduled_date' => '2026-06-20',
            'status' => 'scheduled',
            'generated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('master-checksheet.show', $checksheet->id))
            ->assertOk()
            ->assertSee('Pengaturan Jadwal PM')
            ->assertSee('Setiap hari')
            ->assertSee('Pause Jadwal');

        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.pause', [$checksheet->id, $schedule->id]),
            ['confirmed' => '1', 'reason' => 'Pemeriksaan keselamatan'],
        )->assertRedirect(route('master-checksheet.show', $checksheet->id));

        $this->assertSame('paused', $schedule->fresh()->lifecycle_status);
        $this->assertFalse((bool) $schedule->fresh()->is_active);
        $interval = $schedule->pauseIntervals()->firstOrFail();
        $this->assertSame('2026-06-15', $interval->paused_on->toDateString());
        $this->assertNull($interval->resumed_on);
        $this->assertSame(1, DB::table('user_activity_logs')
            ->where('module_name', 'pm_schedule')
            ->where('action', 'lifecycle_transition')
            ->where('record_id', $schedule->id)
            ->where('user_id', $this->admin->id)
            ->count());

        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.resume', [$checksheet->id, $schedule->id]),
            ['confirmed' => '1', 'reason' => 'Pemeriksaan selesai'],
        )->assertRedirect(route('master-checksheet.show', $checksheet->id));

        $this->assertSame('active', $schedule->fresh()->lifecycle_status);
        $this->assertTrue((bool) $schedule->fresh()->is_active);
        $this->assertSame('2026-06-15', $interval->fresh()->resumed_on->toDateString());
        $this->assertSame(1, PmScheduleDate::query()->whereKey($scheduleDate->id)->count());
        $this->assertSame(2, DB::table('user_activity_logs')
            ->where('module_name', 'pm_schedule')
            ->where('action', 'lifecycle_transition')
            ->where('record_id', $schedule->id)
            ->count());
    }

    public function test_schedule_lifecycle_controls_are_scoped_and_require_confirmation(): void
    {
        [$checksheet, $schedule] = $this->createLifecycleSchedule('PM-LIFECYCLE-SCOPE');
        [$otherChecksheet] = $this->createLifecycleSchedule('PM-LIFECYCLE-OTHER', $this->otherMachine());

        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.pause', [$checksheet->id, $schedule->id]),
            ['confirmed' => '1'],
        )->assertRedirect(route('master-checksheet.show', $checksheet->id));
        $this->assertSame('paused', $schedule->fresh()->lifecycle_status);
        $this->assertSame(1, $schedule->pauseIntervals()->count());
        $this->assertSame(1, DB::table('user_activity_logs')
            ->where('module_name', 'pm_schedule')
            ->where('action', 'lifecycle_transition')
            ->where('record_id', $schedule->id)
            ->where('user_id', $this->admin->id)
            ->whereNull('description')
            ->count());

        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.pause', [$otherChecksheet->id, $schedule->id]),
            ['confirmed' => '1', 'reason' => 'Alasan valid'],
        )->assertNotFound();
        $this->assertSame('paused', $schedule->fresh()->lifecycle_status);
        $this->assertSame(1, $schedule->pauseIntervals()->count());

        $anotherSchedule = $this->createLifecycleSchedule('PM-LIFECYCLE-VALIDATION')[1];
        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.pause', [$anotherSchedule->checksheetMachine->pm_checksheet_id, $anotherSchedule->id]),
            ['reason' => 'Alasan valid'],
        )->assertSessionHasErrors('confirmed');
        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.pause', [$anotherSchedule->checksheetMachine->pm_checksheet_id, $anotherSchedule->id]),
            ['confirmed' => '1', 'reason' => '   '],
        )->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.pause', [$anotherSchedule->checksheetMachine->pm_checksheet_id, $anotherSchedule->id]),
            ['confirmed' => '1', 'reason' => str_repeat('a', 1001)],
        )->assertSessionHasErrors('reason');
        $this->assertSame('active', $anotherSchedule->fresh()->lifecycle_status);
        $this->assertSame(0, $anotherSchedule->pauseIntervals()->count());
    }

    public function test_non_admin_cannot_mutate_a_schedule_lifecycle(): void
    {
        [$checksheet, $schedule] = $this->createLifecycleSchedule('PM-LIFECYCLE-AUTH');

        $this->actingAs($this->operator)->patch(
            route('master-checksheet.schedules.pause', [$checksheet->id, $schedule->id]),
            ['confirmed' => '1', 'reason' => 'Alasan valid'],
        )->assertRedirect('/403');
        $this->assertSame('active', $schedule->fresh()->lifecycle_status);
        $this->assertSame(0, $schedule->pauseIntervals()->count());
    }

    public function test_repeated_pause_is_rejected_without_duplicate_interval_or_audit(): void
    {
        [$checksheet, $schedule] = $this->createLifecycleSchedule('PM-LIFECYCLE-REPEAT');
        $url = route('master-checksheet.schedules.pause', [$checksheet->id, $schedule->id]);

        $this->actingAs($this->admin)->patch($url, ['confirmed' => '1'])->assertRedirect();
        $this->actingAs($this->admin)->patch($url, ['confirmed' => '1'])->assertRedirect();

        $this->assertSame('paused', $schedule->fresh()->lifecycle_status);
        $this->assertSame(1, $schedule->pauseIntervals()->count());
        $this->assertSame(1, DB::table('user_activity_logs')->where('record_id', $schedule->id)->where('action', 'lifecycle_transition')->count());
    }

    public function test_ended_schedule_cannot_be_paused(): void
    {
        [$checksheet, $schedule] = $this->createLifecycleSchedule('PM-LIFECYCLE-ENDED');
        $schedule->forceFill(['lifecycle_status' => 'ended', 'is_active' => false])->save();

        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.pause', [$checksheet->id, $schedule->id]),
            ['confirmed' => '1'],
        )->assertRedirect();

        $this->assertSame('ended', $schedule->fresh()->lifecycle_status);
        $this->assertSame(0, $schedule->pauseIntervals()->count());
        $this->assertSame(0, DB::table('user_activity_logs')->where('record_id', $schedule->id)->where('action', 'lifecycle_transition')->count());
    }

    public function test_legacy_paused_schedule_without_interval_cannot_be_resumed(): void
    {
        [$checksheet, $schedule] = $this->createLifecycleSchedule('PM-LIFECYCLE-LEGACY');
        $schedule->forceFill(['lifecycle_status' => 'paused', 'is_active' => false])->save();

        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.resume', [$checksheet->id, $schedule->id]),
            ['confirmed' => '1'],
        )->assertRedirect();

        $this->assertSame('paused', $schedule->fresh()->lifecycle_status);
        $this->assertSame(0, $schedule->pauseIntervals()->count());
        $this->assertSame(0, DB::table('user_activity_logs')->where('record_id', $schedule->id)->where('action', 'lifecycle_transition')->count());
    }

    public function test_unauthenticated_user_cannot_mutate_schedule_lifecycle(): void
    {
        [$checksheet, $schedule] = $this->createLifecycleSchedule('PM-LIFECYCLE-GUEST');

        $this->patch(
            route('master-checksheet.schedules.pause', [$checksheet->id, $schedule->id]),
            ['confirmed' => '1'],
        )->assertRedirect('/login');

        $this->assertSame('active', $schedule->fresh()->lifecycle_status);
        $this->assertSame(0, $schedule->pauseIntervals()->count());
        $this->assertSame(0, DB::table('user_activity_logs')->where('record_id', $schedule->id)->where('action', 'lifecycle_transition')->count());
    }

    public function test_schedule_lifecycle_audit_failure_rolls_back_pause(): void
    {
        [$checksheet, $schedule] = $this->createLifecycleSchedule('PM-LIFECYCLE-ROLLBACK');
        $this->mock(ActivityLogService::class, function ($mock): void {
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        });

        $this->actingAs($this->admin)->patch(
            route('master-checksheet.schedules.pause', [$checksheet->id, $schedule->id]),
            ['confirmed' => '1', 'reason' => 'Alasan valid'],
        )->assertRedirect(route('master-checksheet.show', $checksheet->id));

        $this->assertSame('active', $schedule->fresh()->lifecycle_status);
        $this->assertTrue((bool) $schedule->fresh()->is_active);
        $this->assertSame(0, $schedule->pauseIntervals()->count());
        $this->assertSame(0, DB::table('user_activity_logs')
            ->where('module_name', 'pm_schedule')
            ->where('record_id', $schedule->id)
            ->count());
    }

    /**
     * @return array{0: PmChecksheet, 1: PmSchedule}
     */
    private function createLifecycleSchedule(string $code, ?Machine $machine = null): array
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => $code,
            'checksheet_name' => 'Lifecycle test schedule',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => ($machine ?? $this->machine)->id,
            'created_by' => $this->admin->id,
        ]);
        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'frequency_type' => 'daily',
            'operational_from' => '2026-06-01',
            'start_date' => '2026-06-01',
            'generate_until' => '2026-07-01',
            'is_active' => true,
            'lifecycle_status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        return [$checksheet, $schedule];
    }

    private function otherMachine(): Machine
    {
        $location = Location::query()->create([
            'location_code' => 'LOC-OTHER',
            'location_name' => 'Other location',
            'is_active' => true,
        ]);

        return Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-OTHER',
            'machine_name' => 'Other Machine',
            'qr_token' => 'qr-mc-other',
            'is_active' => true,
        ]);
    }

    /**
     * Payload wizard dengan action_ids harus menyimpan pivot urut 1-based sesuai
     * urutan pilihan user, dan tidak boleh menulis kolom legacy action_options.
     */
    public function test_explicit_master_action_ids_persist_ordered_pivot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $nyalakan = PmAction::query()->create(['action_label' => 'NYALAKAN', 'is_active' => true]);
        $matikan = PmAction::query()->create(['action_label' => 'MATIKAN', 'is_active' => true]);
        $bersihkan = PmAction::query()->create(['action_label' => 'BERSIHKAN', 'is_active' => true]);

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-ACT-01',
            'checksheet_name' => 'PM Action Pivot',
            'description' => 'Pivot order test',
            'is_active' => '1',
            'wizard_payload' => json_encode(
                $this->actionStandardPayload([$bersihkan->id, $nyalakan->id, $matikan->id]),
                JSON_THROW_ON_ERROR,
            ),
        ])->assertRedirect();

        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $positions = DB::table('pm_checksheet_standard_actions')
            ->where('pm_checksheet_standard_id', $standard->id)
            ->pluck('pm_action_id', 'position')
            ->all();

        $this->assertSame([
            1 => $bersihkan->id,
            2 => $nyalakan->id,
            3 => $matikan->id,
        ], $positions);

        // Payload berbasis action_ids tidak menyentuh proyeksi legacy.
        $this->assertNull(DB::table('pm_checksheet_standards')->where('id', $standard->id)->value('action_options'));
    }

    /**
     * Perubahan urutan hanya mengganti isi pivot; jumlah baris tetap dan posisi
     * lama tidak tertinggal (unique standard/position tidak boleh dilanggar).
     */
    public function test_reordering_master_actions_replaces_pivot_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $first = PmAction::query()->create(['action_label' => 'NYALAKAN', 'is_active' => true]);
        $second = PmAction::query()->create(['action_label' => 'MATIKAN', 'is_active' => true]);

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-ACT-02',
            'checksheet_name' => 'PM Action Reorder',
            'description' => 'Reorder test',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$first->id, $second->id]), JSON_THROW_ON_ERROR),
        ])->assertRedirect();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-ACT-02')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-ACT-02',
            'checksheet_name' => 'PM Action Reorder',
            'description' => 'Reorder test',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$second->id, $first->id]), JSON_THROW_ON_ERROR),
        ])->assertRedirect();

        $rows = DB::table('pm_checksheet_standard_actions')
            ->where('pm_checksheet_standard_id', $standard->id)
            ->orderBy('position')
            ->pluck('pm_action_id', 'position')
            ->all();

        $this->assertSame([1 => $second->id, 2 => $first->id], $rows);
        $this->assertSame(2, DB::table('pm_checksheet_standard_actions')->where('pm_checksheet_standard_id', $standard->id)->count());
    }

    /**
     * Sinkronisasi pivot tidak boleh menulis ulang kolom legacy action_options:
     * checksheet lama yang menyimpan opsi bebas harus tetap byte-identik.
     */
    public function test_pivot_sync_preserves_legacy_action_options_bytes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $action = PmAction::query()->create(['action_label' => 'NYALAKAN', 'is_active' => true]);

        $legacyPayload = $this->actionStandardPayload([]);
        unset($legacyPayload['standards']['part-1'][0]['action_ids']);
        $legacyPayload['standards']['part-1'][0]['action_options'] = ['OPSI  LAMA', 'opsi kedua'];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-ACT-03',
            'checksheet_name' => 'PM Action Legacy',
            'description' => 'Legacy preservation test',
            'is_active' => '1',
            'wizard_payload' => json_encode($legacyPayload, JSON_THROW_ON_ERROR),
        ])->assertRedirect();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-ACT-03')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $rawBefore = DB::table('pm_checksheet_standards')->where('id', $standard->id)->value('action_options');

        $this->assertJsonStringEqualsJsonString('["OPSI  LAMA","opsi kedua"]', (string) $rawBefore);

        // Update memakai action_ids eksplisit tanpa key action_options.
        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-ACT-03',
            'checksheet_name' => 'PM Action Legacy',
            'description' => 'Legacy preservation test',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$action->id]), JSON_THROW_ON_ERROR),
        ])->assertRedirect();

        $rawAfter = DB::table('pm_checksheet_standards')->where('id', $standard->id)->value('action_options');

        $this->assertSame($rawBefore, $rawAfter, 'action_options legacy tidak boleh berubah saat sinkronisasi pivot');
        $this->assertDatabaseHas('pm_checksheet_standard_actions', [
            'pm_checksheet_standard_id' => $standard->id,
            'pm_action_id' => $action->id,
            'position' => 1,
        ]);
    }

    /**
     * Master Action nonaktif, tidak ada, non-integer, atau duplikat harus ditolak
     * sebelum pivot ditulis.
     */
    public function test_invalid_master_action_ids_are_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $active = PmAction::query()->create(['action_label' => 'NYALAKAN', 'is_active' => true]);
        $inactive = PmAction::query()->create(['action_label' => 'MATIKAN', 'is_active' => false]);

        $cases = [
            'nonaktif' => [$inactive->id],
            'tidak ada' => [$active->id + 999],
            'bukan integer' => ['abc'],
            'duplikat' => [$active->id, $active->id],
        ];

        foreach ($cases as $label => $actionIds) {
            $this->actingAs($this->admin)->post('/pm/master-checksheet', [
                'checksheet_code' => 'PM-CH-ACT-INVALID',
                'checksheet_name' => 'PM Action Invalid',
                'description' => "Kasus {$label}",
                'is_active' => '1',
                'wizard_payload' => json_encode($this->actionStandardPayload($actionIds), JSON_THROW_ON_ERROR),
            ])->assertSessionHasErrors(['wizard_payload.standards']);
        }

        $this->assertSame(0, DB::table('pm_checksheet_standard_actions')->count());
    }

    public function test_edit_retains_existing_inactive_action_and_displays_its_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $active = PmAction::query()->create(['action_label' => 'BAIK', 'is_active' => true]);
        $inactive = PmAction::query()->create(['action_label' => 'RUSAK', 'is_active' => true]);

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-RETAIN',
            'checksheet_name' => 'Before edit',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$inactive->id, $active->id]), JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-RETAIN')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $this->actingAs($this->admin)
            ->patch("/pm/master-checksheet/actions/{$inactive->id}/nonaktifkan")
            ->assertSessionHas('flash_success');

        $this->actingAs($this->admin)->get("/pm/master-checksheet/{$checksheet->id}/edit")
            ->assertViewHas('actionOptions', fn ($actions): bool => $actions->contains(
                fn (PmAction $action): bool => $action->id === $inactive->id
                    && $action->action_label === 'RUSAK'
                    && $action->is_active === false
            ));

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-RETAIN',
            'checksheet_name' => 'After edit',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$inactive->id, $active->id]), JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}")->assertSessionHasNoErrors();

        $this->assertSame('After edit', $checksheet->fresh()->checksheet_name);
        $this->assertSame(
            [$inactive->id, $active->id],
            $standard->fresh()->actions()->pluck('pm_actions.id')->all(),
        );
    }

    public function test_legacy_action_edit_preserves_options_until_explicit_conversion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $legacyPayload = $this->actionStandardPayload([]);
        unset($legacyPayload['standards']['part-1'][0]['action_ids']);
        $legacyPayload['standards']['part-1'][0]['action_options'] = ['OPSI  LAMA', 'opsi kedua'];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-LEGACY-EDIT',
            'checksheet_name' => 'Legacy before',
            'is_active' => '1',
            'wizard_payload' => json_encode($legacyPayload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-LEGACY-EDIT')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $rawLegacyOptions = $standard->getRawOriginal('action_options');

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-LEGACY-EDIT',
            'checksheet_name' => 'Legacy after',
            'is_active' => '1',
            'wizard_payload' => json_encode($legacyPayload, JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}")->assertSessionHasNoErrors();

        $this->assertSame('Legacy after', $checksheet->fresh()->checksheet_name);
        $this->assertSame($rawLegacyOptions, $standard->fresh()->getRawOriginal('action_options'));
        $this->assertSame(0, $standard->actions()->count());

        $first = PmAction::query()->create(['action_label' => 'NYALAKAN', 'is_active' => true]);
        $second = PmAction::query()->create(['action_label' => 'MATIKAN', 'is_active' => true]);

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-LEGACY-EDIT',
            'checksheet_name' => 'Legacy after',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$second->id, $first->id]), JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}")->assertSessionHasNoErrors();

        $this->assertSame([$second->id, $first->id], $standard->fresh()->actions()->pluck('pm_actions.id')->all());
        $this->assertSame($rawLegacyOptions, $standard->fresh()->getRawOriginal('action_options'));

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-LEGACY-EDIT',
            'checksheet_name' => 'Invalid empty selection',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([]), JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');

        $this->assertSame('Legacy after', $checksheet->fresh()->checksheet_name);
        $this->assertSame([$second->id, $first->id], $standard->fresh()->actions()->pluck('pm_actions.id')->all());
    }

    public function test_existing_inactive_action_can_be_removed_but_not_added_to_another_standard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $active = PmAction::query()->create(['action_label' => 'BAIK', 'is_active' => true]);
        $inactive = PmAction::query()->create(['action_label' => 'RUSAK', 'is_active' => true]);
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-REMOVE',
            'checksheet_name' => 'Before removal',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$active->id, $inactive->id]), JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-REMOVE')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $this->actingAs($this->admin)->patch("/pm/master-checksheet/actions/{$inactive->id}/nonaktifkan")
            ->assertSessionHas('flash_success');

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-REMOVE',
            'checksheet_name' => 'After removal',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$active->id]), JSON_THROW_ON_ERROR),
        ])->assertRedirect("/pm/master-checksheet/{$checksheet->id}")->assertSessionHasNoErrors();

        $this->assertSame([$active->id], $standard->fresh()->actions()->pluck('pm_actions.id')->all());

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-REMOVE',
            'checksheet_name' => 'Invalid addition',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$active->id, $inactive->id]), JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');
        $anotherStandardPayload = $this->actionStandardPayload([$active->id]);
        $anotherStandardPayload['standards']['part-1'][] = [
            'name' => 'Cek fan',
            'input_type' => 'action',
            'action_ids' => [$active->id, $inactive->id],
            'is_required' => true,
            'is_active' => true,
        ];

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-REMOVE',
            'checksheet_name' => 'Invalid other standard',
            'is_active' => '1',
            'wizard_payload' => json_encode($anotherStandardPayload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');

        $this->assertDatabaseMissing('pm_checksheet_standards', ['standard_name' => 'Cek fan']);

        $this->assertSame([$active->id], $standard->fresh()->actions()->pluck('pm_actions.id')->all());
        $this->assertSame('After removal', $checksheet->fresh()->checksheet_name);
    }

    public function test_edit_rejects_only_inactive_mapping_and_action_deactivated_before_save(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $active = PmAction::query()->create(['action_label' => 'BAIK', 'is_active' => true]);
        $toDeactivate = PmAction::query()->create(['action_label' => 'RUSAK', 'is_active' => true]);
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-STATUS-RACE',
            'checksheet_name' => 'Stable',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$active->id, $toDeactivate->id]), JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CH-STATUS-RACE')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $this->actingAs($this->admin)->patch("/pm/master-checksheet/actions/{$toDeactivate->id}/nonaktifkan")
            ->assertSessionHas('flash_success');

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-STATUS-RACE',
            'checksheet_name' => 'Only inactive',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$toDeactivate->id]), JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');

        $newAction = PmAction::query()->create(['action_label' => 'BERSIHKAN', 'is_active' => true]);
        $this->actingAs($this->admin)->get("/pm/master-checksheet/{$checksheet->id}/edit")
            ->assertViewHas('actionOptions', fn ($actions): bool => $actions->contains('id', $newAction->id));
        $newAction->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CH-STATUS-RACE',
            'checksheet_name' => 'Stale selection',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload([$active->id, $toDeactivate->id, $newAction->id]), JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');

        $this->assertSame('Stable', $checksheet->fresh()->checksheet_name);
        $this->assertSame([$active->id, $toDeactivate->id], $standard->fresh()->actions()->pluck('pm_actions.id')->all());
    }

    public function test_explicit_master_action_mapping_reaches_frozen_executor_definition(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $actions = collect(['PROOF BAIK', 'PROOF BERSIHKAN', 'PROOF GANTI'])
            ->map(function (string $label): PmAction {
                $this->actingAs($this->admin)
                    ->post('/pm/master-checksheet/actions', ['action_label' => $label])
                    ->assertRedirect('/pm/master-checksheet/actions')
                    ->assertSessionHasNoErrors();

                return PmAction::query()->where('action_label', $label)->firstOrFail();
            });

        $intendedActionIds = $actions->pluck('id')->all();

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-VERTICAL-PROOF',
            'checksheet_name' => 'Vertical Action Proof',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload($intendedActionIds), JSON_THROW_ON_ERROR),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()
            ->where('checksheet_code', 'PM-CH-VERTICAL-PROOF')
            ->firstOrFail();
        $standard = PmChecksheetStandard::query()
            ->where('pm_checksheet_part_id', PmChecksheetPart::query()
                ->where('pm_checksheet_machine_id', PmChecksheetMachine::query()
                    ->where('pm_checksheet_id', $checksheet->id)
                    ->value('id'))
                ->value('id'))
            ->where('input_type', 'action')
            ->firstOrFail();

        $this->assertSame('action', $standard->input_type);
        $this->assertSame(
            $intendedActionIds,
            $standard->actions()->orderByPivot('position')->pluck('pm_actions.id')->all(),
        );
        $this->assertSame(
            [1, 2, 3],
            $standard->actions()->orderByPivot('position')->pluck('pm_checksheet_standard_actions.position')->all(),
        );
        $this->assertSame(3, $standard->actions()->count());
        $this->assertTrue($standard->actions()->get()->every(fn (PmAction $action): bool => $action->is_active));
        $this->assertNull($standard->getRawOriginal('action_options'));

        $this->actingAs($this->operator)
            ->post('/pm/executor/'.$this->machine->machine_code.'/start')
            ->assertRedirect('/pm/executor/'.$this->machine->machine_code);

        $execution = PmExecution::query()->firstOrFail();
        $this->assertSame('in_progress', $execution->status);
        $this->assertNotNull($execution->definition_snapshot_at);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'pm_schedule_id' => PmSchedule::query()
                ->where('pm_checksheet_machine_id', PmChecksheetMachine::query()
                    ->where('pm_checksheet_id', $checksheet->id)
                    ->value('id'))
                ->value('id'),
            'status' => 'in_progress',
        ]);

        $item = PmExecutionItem::query()
            ->where('pm_execution_id', $execution->id)
            ->where('pm_checksheet_standard_id', $standard->id)
            ->firstOrFail();
        $this->assertSame(
            $actions->pluck('action_label')->all(),
            $item->action_options_snapshot,
        );
        $this->assertNotSame($standard->actions()->pluck('action_label')->all(), []);
        $this->assertNotSame($item->action_options_snapshot, $standard->action_options);

        $this->actingAs($this->operator)
            ->get('/pm/executor/'.$this->machine->machine_code)
            ->assertOk()
            ->assertSee('Cek indikator')
            ->assertSeeInOrder($actions->pluck('action_label')->all());
    }

    public function test_started_pm_keeps_ordered_action_snapshot_after_admin_renames_master_action(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));

        $actions = collect(['PROOF BAIK', 'PROOF BERSIHKAN', 'PROOF GANTI'])
            ->map(function (string $label): PmAction {
                $this->actingAs($this->admin)
                    ->post('/pm/master-checksheet/actions', ['action_label' => $label])
                    ->assertRedirect('/pm/master-checksheet/actions')
                    ->assertSessionHasNoErrors();

                return PmAction::query()->where('action_label', $label)->firstOrFail();
            });
        $initialLabels = ['PROOF BAIK', 'PROOF BERSIHKAN', 'PROOF GANTI'];
        $actionIds = $actions->pluck('id')->all();

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CH-SNAPSHOT-PROOF',
            'checksheet_name' => 'Snapshot Immutability Proof',
            'is_active' => '1',
            'wizard_payload' => json_encode($this->actionStandardPayload($actionIds), JSON_THROW_ON_ERROR),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $this->assertSame('action', $standard->input_type);
        $this->assertNull($standard->action_options);
        $this->assertSame($actionIds, $standard->actions()->orderByPivot('position')->pluck('pm_actions.id')->all());
        $this->assertSame($initialLabels, $standard->actions()->orderByPivot('position')->pluck('action_label')->all());

        $executorUrl = '/pm/executor/'.$this->machine->machine_code;
        $this->actingAs($this->operator)->post($executorUrl.'/start')
            ->assertRedirect($executorUrl);

        $execution = PmExecution::query()->firstOrFail();
        $this->assertSame('in_progress', $execution->status);
        $this->assertNotNull($execution->definition_snapshot_at);
        $this->assertSame(1, PmExecution::query()->count());

        $item = PmExecutionItem::query()
            ->where('pm_execution_id', $execution->id)
            ->where('pm_checksheet_standard_id', $standard->id)
            ->firstOrFail();
        $this->assertSame($initialLabels, $item->action_options_snapshot);
        $snapshotAtStart = $execution->definition_snapshot_at;
        $itemId = $item->id;

        $this->actingAs($this->admin)
            ->patch('/pm/master-checksheet/actions/'.$actions[1]->id, ['action_label' => 'PROOF CEK ULANG'])
            ->assertRedirect('/pm/master-checksheet/actions')
            ->assertSessionHasNoErrors();

        $this->assertSame($actionIds, $standard->actions()->orderByPivot('position')->pluck('pm_actions.id')->all());
        $this->assertSame(
            ['PROOF BAIK', 'PROOF CEK ULANG', 'PROOF GANTI'],
            $standard->actions()->orderByPivot('position')->pluck('action_label')->all(),
        );
        $this->assertSame(1, PmExecution::query()->count());
        $this->assertSame('in_progress', $execution->fresh()->status);
        $this->assertTrue($execution->fresh()->definition_snapshot_at->equalTo($snapshotAtStart));
        $this->assertSame($itemId, $item->fresh()->id);
        $this->assertSame($initialLabels, $item->fresh()->action_options_snapshot);

        $this->actingAs($this->operator)->get($executorUrl)
            ->assertOk()
            ->assertViewHas('execution', fn (PmExecution $shown): bool => $shown->id === $execution->id)
            ->assertSee('Cek indikator')
            ->assertSeeInOrder([
                'data-option-value="PROOF BAIK"',
                'data-option-value="PROOF BERSIHKAN"',
                'data-option-value="PROOF GANTI"',
            ], false)
            ->assertDontSee('data-option-value="PROOF CEK ULANG"', false);
    }

    public function test_action_selection_modes_persist_and_invalid_or_non_action_modes_are_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $action = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$action->id]);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-MODE',
            'checksheet_name' => 'Mode Proof',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $this->assertSame('multiple', $standard->selection_mode);
        $this->assertSame('multiple', $standard->effectiveSelectionMode());

        $payload['standards']['part-1'][0]['selection_mode'] = 'single';
        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-MODE')->firstOrFail();
        $this->actingAs($this->admin)->put('/pm/master-checksheet/'.$checksheet->id, [
            'checksheet_code' => 'PM-MODE',
            'checksheet_name' => 'Mode Proof',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
        $this->assertSame('single', $standard->fresh()->selection_mode);
        unset($payload['standards']['part-1'][0]['selection_mode']);
        $this->actingAs($this->admin)->put('/pm/master-checksheet/'.$checksheet->id, [
            'checksheet_code' => 'PM-MODE',
            'checksheet_name' => 'Mode Proof',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
        $this->assertSame('single', $standard->fresh()->selection_mode);

        $payload['standards']['part-1'][0]['selection_mode'] = 'invalid';
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-MODE-INVALID',
            'checksheet_name' => 'Invalid Mode',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');

        $payload['standards']['part-1'][0] = [
            'name' => 'Cek angka',
            'input_type' => 'number',
            'target_value' => 10,
            'selection_mode' => 'multiple',
        ];
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-MODE-NUMBER',
            'checksheet_name' => 'Number Mode',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');
        unset($payload['standards']['part-1'][0]['selection_mode']);
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-MODE-NUMBER-NULL',
            'checksheet_name' => 'Number without Mode',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
        $numberStandard = PmChecksheetStandard::query()->where('standard_name', 'Cek angka')->firstOrFail();
        $this->assertNull($numberStandard->selection_mode);
        $this->assertNull($numberStandard->effectiveSelectionMode());
    }

    public function test_start_freezes_action_selection_mode_and_legacy_null_means_single(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $action = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$action->id]);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-MODE-START',
            'checksheet_name' => 'Mode Start',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $this->assertSame('multiple', $standard->effectiveSelectionMode());
        $this->actingAs($this->operator)->post('/pm/executor/'.$this->machine->machine_code.'/start')->assertRedirect();
        $item = PmExecutionItem::query()->where('pm_checksheet_standard_id', $standard->id)->firstOrFail();
        $this->assertSame('multiple', $item->selection_mode_snapshot);

        $standard->update(['selection_mode' => 'single']);
        $this->assertSame('multiple', $item->fresh()->effectiveSelectionMode());
        $this->actingAs($this->operator)->get('/pm/executor/'.$this->machine->machine_code)
            ->assertOk()
            ->assertViewHas('parts', fn (array $parts): bool => $parts[0]['items'][0]['selection_mode'] === 'multiple');

        $standard->update(['selection_mode' => null]);
        $this->assertSame('single', $standard->fresh()->effectiveSelectionMode());
        $standard->update(['selection_mode' => 'multiple']);
        $item->update(['selection_mode_snapshot' => null]);
        $this->assertSame('single', $item->fresh()->effectiveSelectionMode());
        $this->actingAs($this->operator)->get('/pm/executor/'.$this->machine->machine_code)
            ->assertViewHas('parts', fn (array $parts): bool => $parts[0]['items'][0]['selection_mode'] === 'single');
    }

    public function test_multiple_legacy_choices_reject_canonical_duplicate_labels_without_saving(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $payload = $this->actionStandardPayload([]);
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';
        $payload['standards']['part-1'][0]['action_options'] = ['OK', ' ok ', 'NG'];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-DUP',
            'checksheet_name' => 'Pilihan ambigu',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');

        $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => 'PM-CHOICE-DUP']);
        $this->assertDatabaseMissing('pm_checksheet_standards', ['standard_name' => 'Cek indikator']);
    }

    public function test_multiple_update_rejects_duplicate_labels_without_rewriting_existing_definition(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $payload = $this->actionStandardPayload([]);
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['action_options'] = ['OK', 'NG'];
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-UPDATE',
            'checksheet_name' => 'Before',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CHOICE-UPDATE')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $beforeOptions = $standard->getRawOriginal('action_options');
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';
        $payload['standards']['part-1'][0]['action_options'] = ['OK', 'Ok'];

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CHOICE-UPDATE',
            'checksheet_name' => 'After',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');

        $this->assertSame('Before', $checksheet->fresh()->checksheet_name);
        $this->assertSame('single', $standard->fresh()->effectiveSelectionMode());
        $this->assertSame($beforeOptions, $standard->fresh()->getRawOriginal('action_options'));
        $this->assertSame([], $standard->fresh()->actions()->pluck('pm_actions.id')->all());
    }

    public function test_multiple_exact_duplicate_choices_are_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $payload = $this->actionStandardPayload([]);
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';
        $payload['standards']['part-1'][0]['action_options'] = ['OK', 'OK'];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-EXACT',
            'checksheet_name' => 'Pilihan ganda',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors(['wizard_payload.standards' => 'Mode Pilih Banyak memerlukan pilihan Action dengan label unik.']);

        $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => 'PM-CHOICE-EXACT']);
    }

    public function test_multiple_mode_uses_retained_mapping_instead_of_stale_legacy_choices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $ok = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $ng = PmAction::query()->create(['action_label' => 'NG', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$ok->id, $ng->id]);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-PIVOT',
            'checksheet_name' => 'Before',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CHOICE-PIVOT')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $standard->update(['action_options' => ['STALE', 'stale']]);
        $legacyBefore = $standard->getRawOriginal('action_options');
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['action_options'] = ['STALE', 'stale'];

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CHOICE-PIVOT',
            'checksheet_name' => 'After',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $this->assertSame('After', $checksheet->fresh()->checksheet_name);
        $this->assertSame('multiple', $standard->fresh()->effectiveSelectionMode());
        $this->assertSame([$ok->id, $ng->id], $standard->fresh()->actions()->pluck('pm_actions.id')->all());
        $this->assertSame($legacyBefore, $standard->fresh()->getRawOriginal('action_options'));
    }

    public function test_multiple_update_rejects_ambiguous_retained_mapping_atomically(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $ok = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $duplicate = PmAction::query()->create(['action_label' => ' ok ', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$ok->id]);

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-MAPPED-DUP',
            'checksheet_name' => 'Before',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CHOICE-MAPPED-DUP')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        DB::table('pm_checksheet_standard_actions')->insert([
            'pm_checksheet_standard_id' => $standard->id,
            'pm_action_id' => $duplicate->id,
            'position' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';
        $payload['standards']['part-1'][0]['action_options'] = ['UNIQUE'];

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CHOICE-MAPPED-DUP',
            'checksheet_name' => 'After',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors(['wizard_payload.standards' => 'Mode Pilih Banyak memerlukan pilihan Action dengan label unik.']);

        $this->assertSame('Before', $checksheet->fresh()->checksheet_name);
        $this->assertSame('single', $standard->fresh()->effectiveSelectionMode());
        $this->assertSame([$ok->id, $duplicate->id], $standard->fresh()->actions()->pluck('pm_actions.id')->all());
    }

    public function test_multiple_update_rejects_all_inactive_mapping_without_falling_back_or_partial_save(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $ok = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $ng = PmAction::query()->create(['action_label' => 'NG', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$ok->id, $ng->id]);

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-ALL-INACTIVE',
            'checksheet_name' => 'Before',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CHOICE-ALL-INACTIVE')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        DB::table('pm_checksheet_standards')->where('id', $standard->id)->update([
            'action_options' => json_encode(['LEGACY', 'FALLBACK'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('pm_actions')->whereIn('id', [$ok->id, $ng->id])->update(['is_active' => false]);
        $beforeOptions = $standard->fresh()->getRawOriginal('action_options');
        $beforeMappings = DB::table('pm_checksheet_standard_actions')
            ->where('pm_checksheet_standard_id', $standard->id)
            ->orderBy('position')
            ->pluck('pm_action_id', 'position')
            ->all();
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';
        $payload['standards']['part-1'][0]['action_options'] = ['LEGACY', 'FALLBACK'];

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CHOICE-ALL-INACTIVE',
            'checksheet_name' => 'After',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors([
            'wizard_payload.standards' => 'Mode Pilih Banyak memerlukan minimal satu pilihan Action aktif.',
        ]);

        $this->assertSame('Before', $checksheet->fresh()->checksheet_name);
        $this->assertSame('single', $standard->fresh()->effectiveSelectionMode());
        $this->assertSame($beforeOptions, $standard->fresh()->getRawOriginal('action_options'));
        $this->assertSame(
            $beforeMappings,
            DB::table('pm_checksheet_standard_actions')->where('pm_checksheet_standard_id', $standard->id)
                ->orderBy('position')->pluck('pm_action_id', 'position')->all(),
        );
    }

    public function test_multiple_update_ignores_inactive_duplicate_mapping_when_start_freezes_active_choices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $ok = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $inactiveDuplicate = PmAction::query()->create(['action_label' => ' ok ', 'is_active' => false]);
        $payload = $this->actionStandardPayload([$ok->id]);

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-INACTIVE',
            'checksheet_name' => 'Before',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-CHOICE-INACTIVE')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        DB::table('pm_checksheet_standard_actions')->insert([
            'pm_checksheet_standard_id' => $standard->id,
            'pm_action_id' => $inactiveDuplicate->id,
            'position' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';
        $payload['standards']['part-1'][0]['action_options'] = ['STALE', 'stale'];

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-CHOICE-INACTIVE',
            'checksheet_name' => 'After',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->operator)->post('/pm/executor/'.$this->machine->machine_code.'/start')
            ->assertSessionHasNoErrors();

        $this->assertSame('After', $checksheet->fresh()->checksheet_name);
        $this->assertSame('multiple', $standard->fresh()->effectiveSelectionMode());
        $this->assertSame([$ok->id, $inactiveDuplicate->id], $standard->fresh()->actions()->pluck('pm_actions.id')->all());
        $item = PmExecutionItem::query()->where('pm_checksheet_standard_id', $standard->id)->firstOrFail();
        $this->assertSame(['OK'], $item->action_options_snapshot);
    }

    public function test_multiple_legacy_unique_choices_preserve_labels_and_commas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $payload = $this->actionStandardPayload([]);
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['selection_mode'] = 'multiple';
        $payload['standards']['part-1'][0]['action_options'] = ['OK, OLI', 'TAMBAH  OLI'];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-UNIQUE',
            'checksheet_name' => 'Pilihan unik',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $this->assertSame('multiple', $standard->effectiveSelectionMode());
        $this->assertSame(['OK, OLI', 'TAMBAH  OLI'], $standard->action_options);
    }

    public function test_single_legacy_duplicate_labels_remain_unchanged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $payload = $this->actionStandardPayload([]);
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['standards']['part-1'][0]['action_options'] = ['OK', ' ok ', 'NG'];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-CHOICE-SINGLE',
            'checksheet_name' => 'Pilihan legacy',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $standard = PmChecksheetStandard::query()->where('standard_name', 'Cek indikator')->firstOrFail();
        $this->assertSame('single', $standard->effectiveSelectionMode());
        $this->assertSame(['OK', ' ok ', 'NG'], $standard->action_options);
    }

    /**
     * @param  array<int, int|string>  $actionIds
     * @return array<string, mixed>
     */
    protected function actionStandardPayload(array $actionIds): array
    {
        return [
            'selected_machine_ids' => [$this->machine->id],
            'parts' => [
                $this->machine->id => [
                    ['id' => 'part-1', 'name' => 'Motor Drive', 'description' => 'Part utama'],
                ],
            ],
            'standards' => [
                'part-1' => [
                    [
                        'name' => 'Cek indikator',
                        'input_type' => 'action',
                        'action_ids' => $actionIds,
                        'is_required' => true,
                        'is_active' => true,
                    ],
                ],
            ],
            'schedule' => [
                'frequency_type' => 'daily',
                'operational_from' => '2026-05-21',
                'weekly_days' => [],
                'monthly_day' => null,
            ],
        ];
    }

    public function test_number_and_range_standards_allow_stale_action_ids_and_valid_decimals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $payload = $this->actionStandardPayload([]);
        $payload['standards']['part-1'][0] = [
            'name' => 'Suhu',
            'input_type' => 'number',
            'target_value' => '-10.25',
            'unit' => 'C',
            'action_ids' => [],
        ];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-NUM-RANGE',
            'checksheet_name' => 'Angka dan range',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pm_checksheet_standards', [
            'standard_name' => 'Suhu',
            'target_value' => '-10.25',
            'min_value' => null,
            'max_value' => null,
        ]);

        $payload['standards']['part-1'][0] = [
            'name' => 'Tegangan',
            'input_type' => 'range',
            'min_value' => '0.00',
            'max_value' => '0',
            'action_ids' => [],
        ];
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-RANGE-ZERO',
            'checksheet_name' => 'Range nol',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pm_checksheet_standards', [
            'standard_name' => 'Tegangan',
            'target_value' => null,
            'min_value' => '0.00',
            'max_value' => '0.00',
        ]);
    }

    public function test_invalid_checksheet_nested_payloads_are_friendly_and_atomic(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $invalidPayloads = [];

        $payload = $this->actionStandardPayload([]);
        $payload['parts'][$this->machine->id][0] = 'invalid';
        $invalidPayloads[] = $payload;

        $payload = $this->actionStandardPayload([]);
        $payload['standards']['part-1'][0]['is_active'] = 'perhaps';
        $invalidPayloads[] = $payload;

        $payload = $this->actionStandardPayload([]);
        $payload['standards']['part-1'][0]['unexpected'] = 'field';
        $invalidPayloads[] = $payload;

        foreach ($invalidPayloads as $index => $payload) {
            $code = 'PM-BAD-PAYLOAD-'.$index;
            $errorKey = $index === 0 ? 'wizard_payload.parts' : 'wizard_payload.standards';
            $this->actingAs($this->admin)->post('/pm/master-checksheet', [
                'checksheet_code' => $code,
                'checksheet_name' => 'Invalid payload',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])->assertSessionHasErrors($errorKey);
            $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => $code]);
        }

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-BAD-JSON',
            'checksheet_name' => 'Invalid JSON',
            'wizard_payload' => '{invalid',
        ])->assertSessionHasErrors();
        $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => 'PM-BAD-JSON']);
    }

    public function test_duplicate_standard_names_are_case_insensitive_and_scoped_to_part(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $action = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$action->id]);
        $payload['standards']['part-1'][0]['name'] = '  Temperatur ';
        $payload['parts'][$this->machine->id][] = ['id' => 'part-2', 'name' => 'Gearbox', 'description' => ''];
        $payload['standards']['part-1'][] = [
            'name' => '  Temperatur ',
            'input_type' => 'action',
            'action_options' => ['OK'],
        ];
        $payload['standards']['part-2'] = [[
            'name' => 'temperatur',
            'input_type' => 'action',
            'action_options' => ['OK'],
        ]];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-DUPLICATE-STANDARD',
            'checksheet_name' => 'Duplicate standard',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');
        $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => 'PM-DUPLICATE-STANDARD']);

        // Sama 'Temperatur' lintas part tetap sah; part-1 memakai standard number.
        $payload['standards']['part-1'] = [
            ['name' => 'Temperatur', 'input_type' => 'number', 'target_value' => '12.5', 'is_required' => true, 'is_active' => true],
        ];
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-SAME-NAME-DIFFERENT-PART',
            'checksheet_name' => 'Names scoped by part',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
    }

    public function test_invalid_numeric_precision_overflow_range_and_unit_are_rejected_atomically(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $invalidStandards = [
            ['input_type' => 'number', 'target_value' => '1.234'],
            ['input_type' => 'number', 'target_value' => '10000000000'],
            ['input_type' => 'range', 'min_value' => '2', 'max_value' => '1'],
            ['input_type' => 'range', 'min_value' => '-10000000000', 'max_value' => '0'],
        ];

        foreach ($invalidStandards as $index => $standard) {
            $payload = $this->actionStandardPayload([]);
            $payload['standards']['part-1'][0] = [
                'name' => 'Numeric '.$index,
                ...$standard,
            ];
            $code = 'PM-BAD-NUMBER-'.$index;
            $this->actingAs($this->admin)->post('/pm/master-checksheet', [
                'checksheet_code' => $code,
                'checksheet_name' => 'Invalid numeric',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])->assertSessionHasErrors('wizard_payload.standards');
            $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => $code]);
        }

        $payload = $this->actionStandardPayload([]);
        $payload['standards']['part-1'][0]['unit'] = str_repeat('u', 31);
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-BAD-UNIT',
            'checksheet_name' => 'Invalid unit',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');
        $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => 'PM-BAD-UNIT']);

        $payload = $this->actionStandardPayload([]);
        $payload['standards']['part-1'][0]['name'] = str_repeat('S', 151);
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-BAD-STANDARD-NAME-LENGTH',
            'checksheet_name' => 'Invalid standard name length',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.standards');
        $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => 'PM-BAD-STANDARD-NAME-LENGTH']);
    }

    public function test_edit_preserves_inactive_status_when_omitted_and_accepts_explicit_changes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $action = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$action->id]);
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-INACTIVE-EDIT',
            'checksheet_name' => 'Inactive checksheet',
            'is_active' => '0',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-INACTIVE-EDIT')->firstOrFail();

        $this->actingAs($this->admin)->get("/pm/master-checksheet/{$checksheet->id}/edit")
            ->assertOk()
            ->assertSee('<option value="0" selected>Nonaktif</option>', false);

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => $checksheet->checksheet_code,
            'checksheet_name' => 'Still inactive',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id, 'is_active' => false]);

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => $checksheet->checksheet_code,
            'checksheet_name' => 'Activated explicitly',
            'is_active' => '1',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id, 'is_active' => true]);

        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => $checksheet->checksheet_code,
            'checksheet_name' => 'Invalid status',
            'is_active' => 'active',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('is_active');
        $this->assertDatabaseHas('pm_checksheets', ['id' => $checksheet->id, 'is_active' => true]);
    }

    public function test_failed_store_reopens_wizard_at_relevant_step_and_preserves_input(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $action = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$action->id]);
        $payload['standards']['part-1'][] = [
            'name' => 'Cek Indikator',
            'input_type' => 'action',
            'action_options' => ['OK'],
        ];
        $submittedJson = json_encode($payload, JSON_THROW_ON_ERROR);

        // Nama standard duplikat pada part yang sama -> buka Step 4.
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-STEP-STANDARDS',
            'checksheet_name' => 'Step Proof',
            'wizard_payload' => $submittedJson,
        ])->assertSessionHasErrors('wizard_payload.standards')
            ->assertSessionHasInput('wizard_payload', $submittedJson)
            ->assertSessionHasInput('checksheet_name', 'Step Proof');

        $this->actingAs($this->admin)->followingRedirects()->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-STEP-STANDARDS',
            'checksheet_name' => 'Step Proof',
            'wizard_payload' => $submittedJson,
        ])->assertOk()
            ->assertSee('data-error-step="4"', false)
            ->assertSee('Nama standard duplikat')
            ->assertSee('Cek indikator');

        $this->assertDatabaseMissing('pm_checksheets', ['checksheet_code' => 'PM-STEP-STANDARDS']);
    }

    public function test_failed_store_header_error_reopens_wizard_at_step_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $payload = $this->actionStandardPayload([]);
        $payload['standards']['part-1'][0]['input_type'] = 'number';
        $payload['standards']['part-1'][0]['target_value'] = '10';
        unset($payload['standards']['part-1'][0]['action_ids']);

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-STEP-HEADER',
            'checksheet_name' => '',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('checksheet_name');

        $this->actingAs($this->admin)->followingRedirects()->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-STEP-HEADER',
            'checksheet_name' => '',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertOk()
            ->assertSee('data-error-step="1"', false);
    }

    public function test_failed_store_schedule_error_reopens_wizard_at_step_five(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $payload = $this->actionStandardPayload([]);
        $payload['standards']['part-1'][0]['input_type'] = 'number';
        $payload['standards']['part-1'][0]['target_value'] = '10';
        unset($payload['standards']['part-1'][0]['action_ids']);
        $payload['schedule'] = [
            'frequency_type' => 'weekly',
            'operational_from' => '2026-05-21',
            'weekly_days' => [],
            'monthly_day' => null,
        ];

        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-STEP-SCHEDULE',
            'checksheet_name' => 'Schedule Proof',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors('wizard_payload.schedule');

        $this->actingAs($this->admin)->followingRedirects()->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-STEP-SCHEDULE',
            'checksheet_name' => 'Schedule Proof',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertOk()
            ->assertSee('data-error-step="5"', false);
    }

    public function test_failed_edit_reopens_wizard_at_standards_step_preserving_edit_input(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 00:00:00', 'Asia/Jakarta'));
        $action = PmAction::query()->create(['action_label' => 'OK', 'is_active' => true]);
        $payload = $this->actionStandardPayload([$action->id]);
        $this->actingAs($this->admin)->post('/pm/master-checksheet', [
            'checksheet_code' => 'PM-STEP-EDIT',
            'checksheet_name' => 'Edit Proof',
            'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors();
        $checksheet = PmChecksheet::query()->where('checksheet_code', 'PM-STEP-EDIT')->firstOrFail();

        $payload['standards']['part-1'][0]['name'] = 'Nama Standard Baru';
        $payload['standards']['part-1'][] = [
            'name' => 'NAMA STANDARD BARU',
            'input_type' => 'action',
            'action_options' => ['OK'],
        ];
        $submittedJson = json_encode($payload, JSON_THROW_ON_ERROR);

        // Edit path: validasi gagal pada standard -> buka Step 4 dengan isian edit utuh.
        $this->actingAs($this->admin)->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-STEP-EDIT',
            'checksheet_name' => 'Edit Proof Revised',
            'wizard_payload' => $submittedJson,
        ])->assertSessionHasErrors('wizard_payload.standards')
            ->assertSessionHasInput('checksheet_name', 'Edit Proof Revised');

        $this->actingAs($this->admin)->followingRedirects()->put("/pm/master-checksheet/{$checksheet->id}", [
            'checksheet_code' => 'PM-STEP-EDIT',
            'checksheet_name' => 'Edit Proof Revised',
            'wizard_payload' => $submittedJson,
        ])->assertOk()
            ->assertSee('data-error-step="4"', false)
            ->assertSee('Nama Standard Baru');

        $this->assertSame('Edit Proof', $checksheet->fresh()->checksheet_name);
    }
}
