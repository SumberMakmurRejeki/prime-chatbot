<?php

namespace Tests\Feature\Feature\Master;

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmAction;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetStandard;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\Master\PmChecksheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MasterPmChecksheetVueCandidateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_open_candidate_create_page_and_operator_is_denied(): void
    {
        $admin = $this->createUser('admin');
        $operator = $this->createUser('operator');

        $this->actingAs($admin)
            ->get(route('master-checksheet.vue-candidate.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Checksheet/Form')
                ->where('isEdit', false));

        $this->actingAs($operator)
            ->get(route('master-checksheet.vue-candidate.create'))
            ->assertRedirect('/403');
    }

    public function test_operator_cannot_update_candidate_checksheet(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $operator = $this->createUser('operator');
        $machine = $this->createMachine();
        [$checksheet] = $this->createChecksheetWithSchedule($machine);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
        ]);

        $this->actingAs($operator)
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Operator must not update',
                'description' => 'Forbidden candidate update',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect('/403');

        $this->assertSame('Preview Checksheet', $checksheet->fresh()->checksheet_name);
        $this->assertSame(1, PmChecksheetMachine::query()->count());
        $this->assertSame(1, PmSchedule::query()->count());
    }

    public function test_invalid_candidate_create_redirects_to_candidate_and_retains_input(): void
    {
        $admin = $this->createUser('admin');

        $this->followingRedirects()
            ->actingAs($admin)
            ->from(route('master-checksheet.vue-candidate.create'))
            ->post(route('master-checksheet.vue-candidate.store'), [
                'checksheet_code' => 'candidate-invalid',
                'checksheet_name' => '',
                'description' => 'retained candidate create input',
                'is_active' => '0',
                'wizard_payload' => '{}',
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Checksheet/Form')
                ->where('checksheet.checksheet_code', 'candidate-invalid')
                ->where('checksheet.checksheet_name', '')
                ->where('checksheet.description', 'retained candidate create input')
                ->where('checksheet.is_active', false));
    }

    public function test_invalid_candidate_update_redirects_to_candidate_edit_and_retains_input(): void
    {
        $admin = $this->createUser('admin');
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'CANDIDATE-UPDATE',
            'checksheet_name' => 'Existing candidate checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
        ]);

        $this->followingRedirects()
            ->actingAs($admin)
            ->from(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => 'CANDIDATE-UPDATE',
                'checksheet_name' => '',
                'description' => 'retained candidate update input',
                'wizard_payload' => '{}',
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Checksheet/Form')
                ->where('checksheet.checksheet_code', 'CANDIDATE-UPDATE')
                ->where('checksheet.checksheet_name', '')
                ->where('checksheet.description', 'retained candidate update input')
                ->where('checksheet.is_active', true));
    }

    public function test_candidate_action_ids_and_selection_mode_persist_on_nested_create_and_edit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        $firstAction = PmAction::query()->create(['action_label' => 'CHECK CANDIDATE A', 'is_active' => true]);
        $secondAction = PmAction::query()->create(['action_label' => 'CHECK CANDIDATE B', 'is_active' => true]);
        $payload = $this->validPayload($machine, [$firstAction->id, $secondAction->id]);

        $this->actingAs($admin)
            ->post(route('master-checksheet.vue-candidate.store'), [
                'checksheet_code' => 'CANDIDATE-ACTION',
                'checksheet_name' => 'Candidate action checksheet',
                'description' => 'Nested action create',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect();

        $checksheet = PmChecksheet::query()->where('checksheet_code', 'CANDIDATE-ACTION')->firstOrFail();
        $standard = PmChecksheetStandard::query()->where('standard_name', 'Check candidate state')->firstOrFail();
        $this->assertSame('multiple', $standard->selection_mode);
        $this->assertSame([
            1 => $firstAction->id,
            2 => $secondAction->id,
        ], DB::table('pm_checksheet_standard_actions')
            ->where('pm_checksheet_standard_id', $standard->id)
            ->orderBy('position')
            ->pluck('pm_action_id', 'position')
            ->all());

        $this->actingAs($admin)
            ->get(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Checksheet/Form')
                ->where('actions.0.id', $firstAction->id)
                ->where('actions.1.id', $secondAction->id)
                ->where('payload.standards.'.$standard->pm_checksheet_part_id.'.0.action_ids', [$firstAction->id, $secondAction->id])
                ->where('payload.standards.'.$standard->pm_checksheet_part_id.'.0.selection_mode', 'multiple'));

        $payload['standards']['candidate-part'][0]['action_ids'] = [(string) $secondAction->id, (string) $firstAction->id];
        $payload['standards']['candidate-part'][0]['selection_mode'] = 'single';

        $this->actingAs($admin)
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Candidate action checksheet revised',
                'description' => 'Nested action edit',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect();

        $this->assertSame('single', $standard->fresh()->selection_mode);
        $this->assertSame([
            1 => $secondAction->id,
            2 => $firstAction->id,
        ], DB::table('pm_checksheet_standard_actions')
            ->where('pm_checksheet_standard_id', $standard->id)
            ->orderBy('position')
            ->pluck('pm_action_id', 'position')
            ->all());
    }

    public function test_candidate_update_rejects_omitting_an_existing_machine_assignment(): void
    {
        $admin = $this->createUser('admin');
        $firstMachine = $this->createMachine('first');
        $secondMachine = $this->createMachine('second');
        [$checksheet, $firstSchedule] = $this->createChecksheetWithSchedule($firstMachine);
        $secondAssignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $secondMachine->id,
            'assigned_at' => now(),
        ]);
        $secondSchedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $secondAssignment->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
            'start_date' => '2026-06-01',
            'generate_until' => '2026-09-01',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
        $scheduleDateId = DB::table('pm_schedule_dates')->insertGetId([
            'pm_schedule_id' => $secondSchedule->id,
            'machine_id' => $secondMachine->id,
            'scheduled_date' => '2026-07-01',
            'status' => 'waiting_review',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $executionId = DB::table('pm_executions')->insertGetId([
            'pm_schedule_date_id' => $scheduleDateId,
            'machine_id' => $secondMachine->id,
            'status' => 'waiting_review',
            'started_at' => now(),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pm_execution_history')->insert([
            'pm_execution_id' => $executionId,
            'changed_at' => now(),
            'field_name' => 'status',
            'old_value' => 'in_progress',
            'new_value' => 'waiting_review',
            'change_note' => 'Candidate omission regression fixture',
            'created_at' => now(),
        ]);
        $payload = $this->validSchedulePayload($firstMachine, [
            'frequency_type' => $firstSchedule->frequency_type,
            'weekly_days' => $firstSchedule->weekly_days,
            'monthly_day' => $firstSchedule->monthly_day,
            'operational_from' => $firstSchedule->operational_from,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Must remain unchanged',
                'description' => 'Rejected machine omission',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

        $response->assertRedirect(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->assertSessionHasErrors([
                'selected_machine_ids' => 'Mesin yang sudah ditetapkan tidak dapat dihapus dari checksheet. Pilih kembali semua mesin yang ada untuk menyimpan perubahan.',
            ]);
        $this->assertSame('Preview Checksheet', $checksheet->fresh()->checksheet_name);
        $this->assertSame([
            $firstMachine->id,
            $secondMachine->id,
        ], $checksheet->machineAssignments()->orderBy('machine_id')->pluck('machine_id')->all());
        $this->assertSame(2, PmSchedule::query()->count());
        $this->assertDatabaseHas('pm_schedule_dates', ['id' => $scheduleDateId, 'status' => 'waiting_review']);
        $this->assertDatabaseHas('pm_executions', ['id' => $executionId, 'status' => 'waiting_review']);
        $this->assertDatabaseHas('pm_execution_history', [
            'pm_execution_id' => $executionId,
            'field_name' => 'status',
            'new_value' => 'waiting_review',
        ]);
    }

    public function test_candidate_update_succeeds_when_all_existing_machine_assignments_are_selected(): void
    {
        $admin = $this->createUser('admin');
        $firstMachine = $this->createMachine('first');
        $secondMachine = $this->createMachine('second');
        [$checksheet, $firstSchedule] = $this->createChecksheetWithSchedule($firstMachine);
        $secondAssignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $secondMachine->id,
            'assigned_at' => now(),
        ]);
        PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $secondAssignment->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
            'start_date' => '2026-06-01',
            'generate_until' => '2026-09-01',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
        $payload = $this->validSchedulePayload($firstMachine, [
            'frequency_type' => $firstSchedule->frequency_type,
            'weekly_days' => $firstSchedule->weekly_days,
            'monthly_day' => $firstSchedule->monthly_day,
            'operational_from' => $firstSchedule->operational_from,
        ]);
        $payload['selected_machine_ids'] = [(string) $firstMachine->id, (string) $secondMachine->id];
        $payload['parts'][(string) $secondMachine->id] = [
            ['id' => 'candidate-schedule-part-second', 'name' => 'Candidate schedule part second', 'description' => 'Schedule test'],
        ];
        $payload['standards']['candidate-schedule-part-second'] = $payload['standards']['candidate-schedule-part'];

        $this->actingAs($admin)
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Preserved assignments update',
                'description' => 'All existing assignments retained',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('master-checksheet.show', $checksheet->id));

        $this->assertSame('Preserved assignments update', $checksheet->fresh()->checksheet_name);
        $this->assertSame(2, $checksheet->machineAssignments()->count());
    }

    public function test_candidate_put_rejects_schedule_change_until_preview_is_applied(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet, $schedule] = $this->createChecksheetWithSchedule($machine);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'daily',
            'weekly_days' => [],
            'monthly_day' => null,
            'operational_from' => '2026-07-01',
        ]);

        $this->actingAs($admin)
            ->from(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Must not save direct schedule change',
                'description' => 'Direct update bypass attempt',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->assertSessionHasErrors('wizard_payload.schedule');

        $this->assertSame('Preview Checksheet', $checksheet->fresh()->checksheet_name);
        $this->assertSame('weekly', $schedule->fresh()->frequency_type);
        $this->assertSame('2026-06-01', $schedule->fresh()->operational_from->toDateString());
        $this->assertSame(0, PmScheduleDate::query()->count());
    }

    public function test_candidate_put_accepts_schedule_after_authoritative_apply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet, $schedule] = $this->createChecksheetWithSchedule($machine);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'daily',
            'weekly_days' => [],
            'monthly_day' => null,
            'operational_from' => '2026-07-01',
        ]);

        $preview = $this->actingAs($admin)
            ->postJson(route('master-checksheet.preview-schedule-update', $checksheet->id), [
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->postJson(route('master-checksheet.apply-schedule-update', $checksheet->id), [
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'token' => $preview['token'],
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'applied');

        $this->assertSame('daily', $schedule->fresh()->frequency_type);

        $this->actingAs($admin)
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Applied schedule update',
                'description' => 'Updated after apply',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('master-checksheet.show', $checksheet->id));

        $this->assertSame('Applied schedule update', $checksheet->fresh()->checksheet_name);
        $this->assertSame('daily', $schedule->fresh()->frequency_type);
        $this->assertSame('2026-07-01', $schedule->fresh()->operational_from->toDateString());
    }

    public function test_candidate_put_with_unchanged_schedule_preserves_execution_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet, $schedule] = $this->createChecksheetWithSchedule($machine);
        $scheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => '2026-06-15',
            'status' => 'in_progress',
        ]);
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDate->id,
            'machine_id' => $machine->id,
            'status' => 'in_progress',
        ]);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
        ]);

        $this->actingAs($admin)
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Unchanged schedule save',
                'description' => 'Preserve history',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('master-checksheet.show', $checksheet->id));

        $this->assertSame('weekly', $schedule->fresh()->frequency_type);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $scheduleDate->id,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('pm_executions', [
            'id' => $execution->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_admin_can_edit_checksheet_with_existing_inactive_machine_and_preserve_schedule_history(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet, $schedule] = $this->createChecksheetWithSchedule($machine);
        $assignment = PmChecksheetMachine::query()
            ->where('pm_checksheet_id', $checksheet->id)
            ->where('machine_id', $machine->id)
            ->firstOrFail();
        $machine->forceFill(['is_active' => false])->save();

        $scheduleDates = [
            PmScheduleDate::query()->create([
                'pm_schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'scheduled_date' => '2026-06-15',
                'status' => 'in_progress',
            ]),
            PmScheduleDate::query()->create([
                'pm_schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'scheduled_date' => '2026-06-18',
                'status' => 'scheduled',
            ]),
        ];
        PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDates[0]->id,
            'machine_id' => $machine->id,
            'status' => 'in_progress',
        ]);
        $scheduleConfiguration = $schedule->only([
            'pm_checksheet_machine_id',
            'frequency_type',
            'weekly_days',
            'monthly_day',
            'operational_from',
            'start_date',
            'generate_until',
            'is_active',
            'lifecycle_status',
        ]);
        $scheduleDateFields = ['operational_from', 'start_date', 'generate_until'];
        foreach ($scheduleDateFields as $field) {
            $scheduleConfiguration[$field] = $scheduleConfiguration[$field]?->toDateString();
        }
        $assignmentBefore = (array) DB::table('pm_checksheet_machines')->where('id', $assignment->id)->first();
        $scheduleDatesBefore = DB::table('pm_schedule_dates')
            ->where('pm_schedule_id', $schedule->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $executionsBefore = DB::table('pm_executions')
            ->whereIn('pm_schedule_date_id', array_column($scheduleDatesBefore, 'id'))
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
        ]);

        $this->actingAs($admin)
            ->get(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payload.selected_machine_ids', [$machine->id]));

        $response = $this->actingAs($admin)
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Updated with assigned inactive machine',
                'description' => 'Ordinary master-field update',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

        $validationErrors = app('session.store')->get('errors')?->getBag('default')->messages() ?? [];
        $this->assertSame(
            [],
            $validationErrors,
            sprintf(
                'Expected a successful Admin edit; got HTTP %d redirect to %s with validation errors %s.',
                $response->getStatusCode(),
                $response->getTargetUrl(),
                json_encode($validationErrors, JSON_THROW_ON_ERROR),
            ),
        );
        $response->assertRedirect(route('master-checksheet.show', $checksheet->id));

        $this->assertSame('Updated with assigned inactive machine', $checksheet->fresh()->checksheet_name);
        $this->assertSame($assignmentBefore, (array) DB::table('pm_checksheet_machines')->where('id', $assignment->id)->first());
        $updatedScheduleConfiguration = $schedule->fresh()->only(array_keys($scheduleConfiguration));
        foreach ($scheduleDateFields as $field) {
            $updatedScheduleConfiguration[$field] = $updatedScheduleConfiguration[$field]?->toDateString();
        }
        $this->assertSame($scheduleConfiguration, $updatedScheduleConfiguration);
        $this->assertSame($scheduleDatesBefore, DB::table('pm_schedule_dates')
            ->where('pm_schedule_id', $schedule->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all());
        $this->assertSame($executionsBefore, DB::table('pm_executions')
            ->whereIn('pm_schedule_date_id', array_column($scheduleDatesBefore, 'id'))
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all());
    }

    public function test_candidate_schedule_date_preview_uses_business_floor_and_month_end_rules(): void
    {
        $this->travelTo(Carbon::parse('2026-02-28 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');

        $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.preview-schedule-dates'), [
                'wizard_payload' => [
                    'frequency_type' => 'monthly',
                    'operational_from' => '2026-01-31',
                    'weekly_days' => [],
                    'monthly_day' => 31,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('dates.0', '2026-02-28')
            ->assertJsonPath('dates.1', '2026-03-31')
            ->assertJsonPath('dates.2', '2026-04-30');
    }

    public function test_schedule_preview_allows_an_existing_inactive_machine_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet] = $this->createChecksheetWithSchedule($machine);
        $machine->forceFill(['is_active' => false])->save();
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'daily',
            'weekly_days' => [],
            'monthly_day' => null,
            'operational_from' => '2026-07-01',
        ]);

        $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.preview-schedule-update', $checksheet->id), [
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'normal');
    }

    public function test_candidate_update_rechecks_new_machine_activity_after_validation(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        $newMachine = $this->createMachine('RACE');
        [$checksheet] = $this->createChecksheetWithSchedule($machine);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-15',
        ]);
        $payload['selected_machine_ids'][] = $newMachine->id;
        $payload['parts'][(string) $newMachine->id] = [
            ['id' => 'race-machine-part', 'name' => 'Race machine part', 'description' => 'Activity race'],
        ];
        $payload['standards']['race-machine-part'] = [[
            'name' => 'Check race machine',
            'input_type' => 'number',
            'target_value' => 10,
            'unit' => 'bar',
            'is_required' => true,
            'is_active' => true,
        ]];
        $activityChanged = false;
        DB::listen(function ($query) use ($newMachine, &$activityChanged): void {
            if (! $activityChanged
                && str_contains(strtolower($query->sql), 'from "machines"')
                && str_contains(strtolower($query->sql), 'count(*)')
                && in_array($newMachine->id, $query->bindings, true)
            ) {
                $activityChanged = true;
                DB::table('machines')->where('id', $newMachine->id)->update(['is_active' => false]);
            }
        });

        $response = $this->actingAs($admin)
            ->from(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Reject machine deactivated during validation',
                'description' => 'Activity changed after validation',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
        $errors = app('session.store')->get('errors')?->getBag('default')->messages() ?? [];
        $this->assertTrue($activityChanged, 'The existence validation query listener must deactivate the newly selected machine.');
        $this->assertSame(
            ['selected_machine_ids' => ['Mesin tidak valid atau sudah nonaktif.']],
            $errors,
            sprintf('Expected the post-validation machine lock to reject this selection; got %s.', json_encode($errors, JSON_THROW_ON_ERROR)),
        );
        $response->assertRedirect(route('master-checksheet.vue-candidate.edit', $checksheet->id));
        $this->assertSame(1, PmChecksheetMachine::query()->count());
        $this->assertSame('Preview Checksheet', $checksheet->fresh()->checksheet_name);
    }

    public function test_candidate_put_rejects_new_machine_without_persisted_schedule_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        $newMachine = $this->createMachine('NEW');
        [$checksheet] = $this->createChecksheetWithSchedule($machine);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
        ]);
        $payload['selected_machine_ids'][] = $newMachine->id;
        $payload['parts'][(string) $newMachine->id] = [
            ['id' => 'candidate-new-machine-part', 'name' => 'New machine part', 'description' => 'Schedule test'],
        ];
        $payload['standards']['candidate-new-machine-part'] = [
            [
                'name' => 'Check new machine pressure',
                'input_type' => 'number',
                'target_value' => 10,
                'unit' => 'bar',
                'is_required' => true,
                'is_active' => true,
            ],
        ];

        $this->actingAs($admin)
            ->from(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Must reject unpreviewed machine schedule',
                'description' => 'New machine bypass attempt',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->assertSessionHasErrors('wizard_payload.schedule');

        $this->assertSame(1, PmChecksheetMachine::query()->count());
        $this->assertSame(1, PmSchedule::query()->count());
        $this->assertSame('Preview Checksheet', $checksheet->fresh()->checksheet_name);
    }

    public function test_admin_cannot_add_new_inactive_machine_to_candidate_checksheet(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        $inactiveMachine = $this->createMachine('INACTIVE');
        [$checksheet, $schedule] = $this->createChecksheetWithSchedule($machine);
        $inactiveMachine->forceFill(['is_active' => false])->save();
        $scheduleDates = [
            PmScheduleDate::query()->create([
                'pm_schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'scheduled_date' => '2026-06-15',
                'status' => 'in_progress',
            ]),
            PmScheduleDate::query()->create([
                'pm_schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'scheduled_date' => '2026-06-18',
                'status' => 'scheduled',
            ]),
        ];
        PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDates[0]->id,
            'machine_id' => $machine->id,
            'status' => 'in_progress',
        ]);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
        ]);
        $payload['selected_machine_ids'][] = $inactiveMachine->id;
        $payload['parts'][(string) $inactiveMachine->id] = [
            ['id' => 'candidate-inactive-part', 'name' => 'Inactive machine part', 'description' => 'Valid candidate part'],
        ];
        $payload['standards']['candidate-inactive-part'] = [
            [
                'name' => 'Check inactive machine pressure',
                'input_type' => 'number',
                'target_value' => 10,
                'unit' => 'bar',
                'is_required' => true,
                'is_active' => true,
            ],
        ];
        $assignmentRowsBefore = DB::table('pm_checksheet_machines')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $scheduleRowsBefore = DB::table('pm_schedules')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $scheduleDateRowsBefore = DB::table('pm_schedule_dates')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $executionRowsBefore = DB::table('pm_executions')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $response = $this->actingAs($admin)
            ->from(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Must reject new inactive machine',
                'description' => 'New inactive machine must not be assigned',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

        $response->assertRedirect(route('master-checksheet.vue-candidate.edit', $checksheet->id))
            ->assertSessionHasErrors([
                'selected_machine_ids.1' => 'Mesin tidak valid atau sudah nonaktif.',
            ]);
        $errors = app('session.store')->get('errors')->getBag('default')->messages();
        $this->assertSame(['selected_machine_ids.1'], array_keys($errors));
        $this->assertDatabaseMissing('pm_checksheet_machines', [
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $inactiveMachine->id,
        ]);
        $this->assertSame('Preview Checksheet', $checksheet->fresh()->checksheet_name);
        $this->assertSame($assignmentRowsBefore, DB::table('pm_checksheet_machines')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all());
        $this->assertSame($scheduleRowsBefore, DB::table('pm_schedules')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all());
        $this->assertSame($scheduleDateRowsBefore, DB::table('pm_schedule_dates')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all());
        $this->assertSame($executionRowsBefore, DB::table('pm_executions')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all());
    }

    public function test_candidate_guard_and_update_share_the_existing_lock_order_in_one_transaction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet] = $this->createChecksheetWithSchedule($machine);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
        ]);
        $baselineTransactionLevel = DB::connection()->transactionLevel();
        $queries = [];
        DB::connection()->beforeExecuting(function (string $query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query),
                'transaction_level' => DB::connection()->transactionLevel(),
            ];
        });

        $this->actingAs($admin)
            ->put(route('master-checksheet.vue-candidate.update', $checksheet->id), [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Atomic candidate update',
                'description' => 'Guard under existing lock order',
                'is_active' => '1',
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('master-checksheet.show', $checksheet->id));

        $checksheetLock = array_key_first(array_filter(
            $queries,
            fn (array $query): bool => $query['transaction_level'] > $baselineTransactionLevel
                && str_starts_with($query['sql'], 'select * from "pm_checksheets"')
                && (str_contains($query['sql'], 'where "id" = ?')
                    || str_contains($query['sql'], '"pm_checksheets"."id" = ?')),
        ));
        $machineLock = array_key_first(array_filter(
            $queries,
            fn (array $query): bool => $query['transaction_level'] > $baselineTransactionLevel
                && str_contains($query['sql'], 'from "machines"')
                && str_contains($query['sql'], 'where "id" in')
                && str_contains($query['sql'], 'order by "id" asc'),
        ));
        $scheduleGuard = array_key_first(array_filter(
            $queries,
            fn (array $query): bool => $query['transaction_level'] > $baselineTransactionLevel
                && str_starts_with($query['sql'], 'select * from "pm_schedules"')
                && str_contains($query['sql'], 'where "lifecycle_status" in')
                && str_contains($query['sql'], 'exists (select * from "pm_checksheet_machines"'),
        ));
        $childMutation = array_key_first(array_filter(
            $queries,
            fn (array $query): bool => $query['transaction_level'] > $baselineTransactionLevel
                && preg_match(
                    '/\b(update|insert into)\s+"?(pm_checksheets|pm_checksheet_machines|pm_checksheet_parts|pm_checksheet_standards|pm_schedules)/i',
                    $query['sql'],
                ) === 1,
        ));

        $this->assertNotNull($checksheetLock, 'The candidate update must lock its checksheet in a transaction.');
        $this->assertNotNull($machineLock, 'The candidate update must lock selected machines in ascending order.');
        $this->assertNotNull($scheduleGuard, 'The schedule guard query must run in that transaction.');
        $this->assertNotNull($childMutation, 'The candidate update must persist child or checksheet changes.');
        $this->assertSame($baselineTransactionLevel + 1, $queries[$checksheetLock]['transaction_level']);
        $this->assertSame($baselineTransactionLevel + 1, $queries[$machineLock]['transaction_level']);
        $this->assertSame($baselineTransactionLevel + 1, $queries[$scheduleGuard]['transaction_level']);
        $this->assertLessThan($machineLock, $checksheetLock);
        $this->assertLessThan($scheduleGuard, $machineLock);
        $this->assertLessThan($childMutation, $scheduleGuard);
    }

    public function test_candidate_preview_apply_creates_new_machine_assignment_and_preserves_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        $newMachine = $this->createMachine('NEW');
        $otherMachine = $this->createMachine('OTHER');
        [$checksheet, $schedule] = $this->createChecksheetWithSchedule($machine);
        $existingAssignment = PmChecksheetMachine::query()
            ->where('pm_checksheet_id', $checksheet->id)
            ->where('machine_id', $machine->id)
            ->firstOrFail();
        $existingAssignment->forceFill([
            'assigned_at' => Carbon::parse('2026-01-15 09:30:00', 'Asia/Jakarta'),
            'created_by' => $admin->id,
        ])->save();
        $existingAssignmentId = $existingAssignment->id;
        $protectedDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => '2026-06-15',
            'status' => 'in_progress',
        ]);
        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $protectedDate->id,
            'machine_id' => $machine->id,
            'status' => 'in_progress',
        ]);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'daily',
            'weekly_days' => [],
            'monthly_day' => null,
            'operational_from' => '2026-07-01',
        ]);
        $payload['selected_machine_ids'][] = $newMachine->id;
        $payload['parts'][(string) $newMachine->id] = [
            ['id' => 'candidate-new-machine-part', 'name' => 'New machine part', 'description' => 'Schedule test'],
        ];
        $payload['standards']['candidate-new-machine-part'] = [[
            'name' => 'Check new machine pressure',
            'input_type' => 'number',
            'target_value' => 10,
            'unit' => 'bar',
            'is_required' => true,
            'is_active' => true,
        ]];

        $preview = $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.preview-schedule-update', $checksheet->id), [
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->assertJsonPath('candidate_assignments.0.machine_id', $newMachine->id)
            ->json();

        $this->assertDatabaseMissing('pm_checksheet_machines', [
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $newMachine->id,
        ]);

        $stalePayload = $payload;
        $stalePayload['selected_machine_ids'][] = $otherMachine->id;
        $stalePayload['parts'][(string) $otherMachine->id] = [
            ['id' => 'candidate-stale-machine-part', 'name' => 'Stale machine part', 'description' => 'Token binding test'],
        ];
        $stalePayload['standards']['candidate-stale-machine-part'] = [[
            'name' => 'Check stale machine',
            'input_type' => 'number',
            'target_value' => 1,
            'unit' => 'bar',
            'is_required' => true,
            'is_active' => true,
        ]];
        $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.apply-schedule-update', $checksheet->id), $this->candidateApplyRequestBody(
                $checksheet,
                $stalePayload,
                $preview['token'],
                'Must reject stale machine selection',
                'Changed selection after preview',
            ))
            ->assertStatus(409)
            ->assertJsonPath('status', 'stale');

        $this->assertDatabaseMissing('pm_checksheet_machines', [
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $newMachine->id,
        ]);
        $this->assertDatabaseMissing('pm_checksheet_machines', [
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $otherMachine->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.apply-schedule-update', $checksheet->id), $this->candidateApplyRequestBody(
                $checksheet,
                $payload,
                $preview['token'],
                'Applied candidate schedule and assignment',
                'New machine through preview and apply',
            ))
            ->assertOk()
            ->assertJsonPath('status', 'applied');
        $this->assertSame(1, PmChecksheetMachine::query()
            ->where('pm_checksheet_id', $checksheet->id)
            ->where('machine_id', $newMachine->id)
            ->count());

        $assignment = PmChecksheetMachine::query()
            ->where('pm_checksheet_id', $checksheet->id)
            ->where('machine_id', $newMachine->id)
            ->firstOrFail();
        $newSchedule = PmSchedule::query()
            ->where('pm_checksheet_machine_id', $assignment->id)
            ->currentEra()
            ->firstOrFail();

        $this->assertSame('Applied candidate schedule and assignment', $checksheet->fresh()->checksheet_name);
        $this->assertSame('daily', $newSchedule->frequency_type);
        $persistedExistingAssignment = $existingAssignment->fresh();
        $this->assertSame($existingAssignmentId, $persistedExistingAssignment->id);
        $this->assertSame('2026-01-15 09:30:00', $persistedExistingAssignment->assigned_at->toDateTimeString());
        $this->assertSame($admin->id, $persistedExistingAssignment->created_by);
        $this->assertDatabaseHas('pm_schedule_dates', [
            'pm_schedule_id' => $newSchedule->id,
            'machine_id' => $newMachine->id,
            'scheduled_date' => '2026-07-01 00:00:00',
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('pm_checksheet_parts', [
            'pm_checksheet_machine_id' => $assignment->id,
            'part_name' => 'New machine part',
        ]);
        $this->assertNotNull(PmChecksheetStandard::query()
            ->where('standard_name', 'Check new machine pressure')
            ->first());
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $protectedDate->id,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('pm_executions', [
            'id' => $execution->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_candidate_apply_persists_request_from_node_builder_with_valid_preview_token(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet, $initialSchedule] = $this->createChecksheetWithSchedule($machine);
        $wizardPayload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'daily',
            'weekly_days' => [],
            'monthly_day' => null,
            'operational_from' => '2026-07-01',
        ]);
        $wizardPayloadJson = json_encode($wizardPayload, JSON_THROW_ON_ERROR);

        $preview = $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.preview-schedule-update', $checksheet->id), [
                'wizard_payload' => $wizardPayloadJson,
            ])
            ->assertOk()
            ->json();
        $this->assertIsString($preview['token'] ?? null);
        $this->assertSame(64, strlen($preview['token']));

        $requestBody = $this->buildCandidateApplyRequestJsonWithNode([
            'form' => [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Persisted from Node Apply builder',
                'description' => 'Cross-runtime request regression',
                'is_active' => '1',
            ],
            'wizardPayload' => $wizardPayloadJson,
            'token' => $preview['token'],
        ]);
        $response = $this->actingAs($admin)->call(
            'POST',
            route('master-checksheet.vue-candidate.apply-schedule-update', $checksheet->id),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $requestBody,
        );

        $response->assertOk()->assertJsonPath('status', 'applied');
        $currentSchedule = PmSchedule::query()
            ->where('pm_checksheet_machine_id', $initialSchedule->pm_checksheet_machine_id)
            ->currentEra()
            ->firstOrFail();
        $persistedChecksheet = $checksheet->fresh();
        $this->assertSame('Persisted from Node Apply builder', $persistedChecksheet->checksheet_name);
        $this->assertSame('Cross-runtime request regression', $persistedChecksheet->description);
        $this->assertTrue($persistedChecksheet->is_active);
        $this->assertSame('daily', $currentSchedule->frequency_type);
        $this->assertSame('2026-07-01', Carbon::parse($currentSchedule->operational_from)->toDateString());
        $this->assertDatabaseHas('pm_checksheet_parts', [
            'pm_checksheet_machine_id' => $initialSchedule->pm_checksheet_machine_id,
            'part_name' => 'Candidate schedule part',
        ]);
    }

    public function test_candidate_apply_rejects_stale_request_from_node_builder_without_changes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet, $schedule] = $this->createChecksheetWithSchedule($machine);
        $existingDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => '2026-06-20',
            'status' => 'scheduled',
        ]);
        $wizardPayload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'daily',
            'weekly_days' => [],
            'monthly_day' => null,
            'operational_from' => '2026-07-01',
        ]);
        $wizardPayloadJson = json_encode($wizardPayload, JSON_THROW_ON_ERROR);

        $preview = $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.preview-schedule-update', $checksheet->id), [
                'wizard_payload' => $wizardPayloadJson,
            ])
            ->assertOk()
            ->json();
        $this->assertIsString($preview['token'] ?? null);
        $this->assertSame(64, strlen($preview['token']));

        $stalePayload = $wizardPayload;
        $stalePayload['schedule']['operational_from'] = '2026-07-02';
        $stalePayloadJson = json_encode($stalePayload, JSON_THROW_ON_ERROR);
        $dateCountBeforeApply = PmScheduleDate::query()
            ->where('pm_schedule_id', $schedule->id)
            ->count();
        $requestBody = $this->buildCandidateApplyRequestJsonWithNode([
            'form' => [
                'checksheet_code' => $checksheet->checksheet_code,
                'checksheet_name' => 'Stale request must not persist',
                'description' => 'Stale request must not persist',
                'is_active' => '0',
            ],
            'wizardPayload' => $stalePayloadJson,
            'token' => $preview['token'],
        ]);
        $response = $this->actingAs($admin)->call(
            'POST',
            route('master-checksheet.vue-candidate.apply-schedule-update', $checksheet->id),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $requestBody,
        );

        $response->assertStatus(409)->assertJsonPath('status', 'stale');
        $persistedChecksheet = $checksheet->fresh();
        $this->assertSame('Preview Checksheet', $persistedChecksheet->checksheet_name);
        $this->assertNull($persistedChecksheet->description);
        $this->assertTrue($persistedChecksheet->is_active);
        $this->assertSame('weekly', $schedule->fresh()->frequency_type);
        $this->assertSame('2026-06-01', Carbon::parse($schedule->fresh()->operational_from)->toDateString());
        $this->assertSame($dateCountBeforeApply, PmScheduleDate::query()
            ->where('pm_schedule_id', $schedule->id)
            ->count());
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $existingDate->id,
            'pm_schedule_id' => $schedule->id,
            'status' => 'scheduled',
        ]);
    }

    /**
     * @param  array{form: array<string, string>, wizardPayload: string, token: string}  $fixture
     */
    private function buildCandidateApplyRequestJsonWithNode(array $fixture): string
    {
        $process = new Process(
            ['node', base_path('tests/JavaScript/candidate-apply-request-runner.js')],
            base_path(),
        );
        $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR));
        $process->setTimeout(5);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertNotSame('', $process->getOutput());

        return $process->getOutput();
    }

    public function test_candidate_apply_rolls_back_schedule_when_master_update_fails_after_apply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:00', 'Asia/Jakarta'));
        $admin = $this->createUser('admin');
        $machine = $this->createMachine();
        [$checksheet, $schedule] = $this->createChecksheetWithSchedule($machine);
        $existingDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => '2026-06-20',
            'status' => 'scheduled',
        ]);
        $payload = $this->validSchedulePayload($machine, [
            'frequency_type' => 'daily',
            'weekly_days' => [],
            'monthly_day' => null,
            'operational_from' => '2026-07-01',
        ]);

        $preview = $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.preview-schedule-update', $checksheet->id), [
                'wizard_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->json();
        $dateCountBeforeApply = PmScheduleDate::query()
            ->where('pm_schedule_id', $schedule->id)
            ->count();
        $checksheetService = \Mockery::mock(PmChecksheetService::class);
        $checksheetService->shouldReceive('update')
            ->once()
            ->andThrow(ValidationException::withMessages([
                'wizard_payload' => 'Simulated candidate master update failure.',
            ]));
        $this->app->instance(PmChecksheetService::class, $checksheetService);

        $this->actingAs($admin)
            ->postJson(route('master-checksheet.vue-candidate.apply-schedule-update', $checksheet->id), $this->candidateApplyRequestBody(
                $checksheet,
                $payload,
                $preview['token'],
                'Should roll back after failed update',
                'Failure after schedule apply',
            ))
            ->assertUnprocessable()
            ->assertJsonPath('errors.wizard_payload.0', 'Simulated candidate master update failure.');

        $this->assertSame('weekly', $schedule->fresh()->frequency_type);
        $this->assertSame('2026-06-01', Carbon::parse($schedule->fresh()->operational_from)->toDateString());
        $this->assertSame('Preview Checksheet', $checksheet->fresh()->checksheet_name);
        $this->assertSame($dateCountBeforeApply, PmScheduleDate::query()
            ->where('pm_schedule_id', $schedule->id)
            ->count());
        $this->assertDatabaseHas('pm_schedule_dates', [
            'id' => $existingDate->id,
            'pm_schedule_id' => $schedule->id,
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseMissing('pm_schedule_dates', [
            'pm_schedule_id' => $schedule->id,
            'scheduled_date' => '2026-07-01 00:00:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $wizardPayload
     * @return array<string, mixed>
     */
    private function candidateApplyRequestBody(
        PmChecksheet $checksheet,
        array $wizardPayload,
        string $token,
        string $checksheetName,
        string $description,
    ): array {
        return [
            'checksheet_code' => $checksheet->checksheet_code,
            'checksheet_name' => $checksheetName,
            'description' => $description,
            'is_active' => '1',
            'wizard_payload' => json_encode($wizardPayload, JSON_THROW_ON_ERROR),
            'token' => $token,
            'confirmed' => 1,
        ];
    }

    /**
     * @return array{0: PmChecksheet, 1: PmSchedule}
     */
    private function createChecksheetWithSchedule(Machine $machine): array
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'CANDIDATE-SCHEDULE',
            'checksheet_name' => 'Preview Checksheet',
            'is_active' => true,
            'assignment_history_known' => true,
        ]);
        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $machine->id,
            'assigned_at' => now(),
        ]);
        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'frequency_type' => 'weekly',
            'weekly_days' => [1, 4],
            'monthly_day' => null,
            'operational_from' => '2026-06-01',
            'start_date' => '2026-06-01',
            'generate_until' => '2026-09-01',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);

        return [$checksheet, $schedule];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    private function validSchedulePayload(Machine $machine, array $schedule): array
    {
        return [
            'selected_machine_ids' => [$machine->id],
            'parts' => [
                (string) $machine->id => [
                    ['id' => 'candidate-schedule-part', 'name' => 'Candidate schedule part', 'description' => 'Schedule test'],
                ],
            ],
            'standards' => [
                'candidate-schedule-part' => [
                    [
                        'name' => 'Check candidate pressure',
                        'input_type' => 'number',
                        'target_value' => 10,
                        'unit' => 'bar',
                        'is_required' => true,
                        'is_active' => true,
                    ],
                ],
            ],
            'schedule' => $schedule,
        ];
    }

    private function createMachine(string $suffix = ''): Machine
    {
        $suffix = $suffix === '' ? '' : '-'.$suffix;
        $location = Location::query()->create([
            'location_code' => 'CANDIDATE-LOC'.$suffix,
            'location_name' => 'Candidate line'.$suffix,
            'is_active' => true,
        ]);

        return Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'CANDIDATE-MACHINE'.$suffix,
            'machine_name' => 'Candidate machine'.$suffix,
            'qr_token' => 'candidate-machine-qr'.$suffix,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, int|string>  $actionIds
     * @return array<string, mixed>
     */
    private function validPayload(Machine $machine, array $actionIds): array
    {
        return [
            'selected_machine_ids' => [$machine->id],
            'parts' => [
                (string) $machine->id => [
                    ['id' => 'candidate-part', 'name' => 'Candidate motor', 'description' => 'Nested part'],
                ],
            ],
            'standards' => [
                'candidate-part' => [
                    [
                        'name' => 'Check candidate state',
                        'input_type' => 'action',
                        'selection_mode' => 'multiple',
                        'action_options' => [],
                        'action_ids' => array_map('strval', $actionIds),
                        'is_required' => true,
                        'is_active' => true,
                    ],
                ],
            ],
            'schedule' => [
                'frequency_type' => 'daily',
                'weekly_days' => [],
                'monthly_day' => null,
                'operational_from' => '2026-05-21',
            ],
        ];
    }

    private function createUser(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' candidate',
            'username' => $role.'.checksheet.candidate',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
