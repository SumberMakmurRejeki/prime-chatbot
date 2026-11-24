<?php

namespace App\Http\Controllers\Master;

use App\Http\Requests\Master\ApplyPmChecksheetVueCandidateScheduleRequest;
use App\Http\Requests\Master\PreviewPmChecksheetVueCandidateScheduleRequest;
use App\Http\Requests\Master\StorePmChecksheetVueCandidateRequest;
use App\Http\Requests\Master\UpdatePmChecksheetVueCandidateRequest;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmSchedule;
use App\Services\PM\BusinessDate;
use App\Services\PM\PlanningPeriodPolicy;
use App\Services\PM\PmScheduleDateGenerator;
use App\Services\PM\StalePreviewException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Isolated local verification surface; production checksheet routes remain Blade. */
class MasterPmChecksheetVueCandidateController extends MasterPmChecksheetController
{
    public function createCandidate(Request $request): InertiaResponse
    {
        $this->assertCandidateEnvironment();

        return Inertia::render('Master/Checksheet/Form', $this->formProps($request, null));
    }

    public function editCandidate(Request $request, int $id): InertiaResponse
    {
        $this->assertCandidateEnvironment();

        return Inertia::render('Master/Checksheet/Form', $this->formProps($request, $this->findChecksheet($id)));
    }

    public function storeCandidate(StorePmChecksheetVueCandidateRequest $request): RedirectResponse
    {
        $this->assertCandidateEnvironment();
        $checksheet = $this->checksheetService->create($request, $request->validated());

        return redirect()->route('master-checksheet.show', $checksheet->id)->with('flash_success', 'Checksheet berhasil ditambahkan.');
    }

    public function updateCandidate(UpdatePmChecksheetVueCandidateRequest $request, int $id): RedirectResponse
    {
        $this->assertCandidateEnvironment();
        $payload = $request->validated();

        DB::transaction(function () use ($request, $id, $payload): void {
            $checksheet = PmChecksheet::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->lockCandidateScheduleRows($checksheet, $payload['selected_machine_ids']);
            $existingMachineIds = $checksheet->machineAssignments()
                ->pluck('machine_id')
                ->map(fn ($machineId): int => (int) $machineId)
                ->all();
            $selectedMachineIds = array_map('intval', $payload['selected_machine_ids']);
            if (array_diff($existingMachineIds, $selectedMachineIds) !== []) {
                throw ValidationException::withMessages([
                    'selected_machine_ids' => 'Mesin yang sudah ditetapkan tidak dapat dihapus dari checksheet. Pilih kembali semua mesin yang ada untuk menyimpan perubahan.',
                ]);
            }

            $this->assertCandidateScheduleConfigurationWasApplied($checksheet, $payload);
            $this->checksheetService->update(
                $request,
                $checksheet,
                $payload,
                array_map('intval', $payload['selected_machine_ids']),
            );
        });

        return redirect()->route('master-checksheet.show', $id)->with('flash_success', 'Checksheet berhasil diperbarui.');
    }

    public function previewCandidateScheduleDates(Request $request): JsonResponse
    {
        $this->assertCandidateEnvironment();
        $rawPayload = $request->input('wizard_payload');
        $payload = is_string($rawPayload) ? json_decode($rawPayload, true) : $rawPayload;
        $validated = validator(['wizard_payload' => $payload], [
            'wizard_payload' => ['required', 'array'],
            'wizard_payload.frequency_type' => ['required', 'in:daily,weekly,monthly'],
            'wizard_payload.operational_from' => ['required', 'date'],
            'wizard_payload.weekly_days' => ['nullable', 'array'],
            'wizard_payload.weekly_days.*' => ['integer', 'between:0,6', 'distinct:strict'],
            'wizard_payload.monthly_day' => ['nullable', 'integer', 'between:1,31'],
        ])->validate()['wizard_payload'];
        $operationalFrom = Carbon::parse($validated['operational_from'], 'Asia/Jakarta')->startOfDay();
        $window = app(PlanningPeriodPolicy::class)->planningWindow(BusinessDate::today(), $operationalFrom);

        return response()->json([
            'dates' => PmScheduleDateGenerator::generate([
                'frequency_type' => $validated['frequency_type'],
                'weekly_days' => $validated['weekly_days'] ?? [],
                'monthly_day' => $validated['monthly_day'] ?? null,
                'start_date' => $window['planning_start']->toDateString(),
                'generate_until' => $window['planning_end']->toDateString(),
            ]),
        ]);
    }

    public function previewCandidateScheduleUpdate(PreviewPmChecksheetVueCandidateScheduleRequest $request, int $id): JsonResponse
    {
        $this->assertCandidateEnvironment();
        $payload = $request->validated()['wizard_payload'];
        $checksheet = $this->findChecksheet($id);

        return response()->json($this->scheduleUpdateService->preview(
            $checksheet,
            $payload['schedule'],
            $payload['selected_machine_ids'],
        ));
    }

    public function applyCandidateScheduleUpdate(ApplyPmChecksheetVueCandidateScheduleRequest $request, int $id): JsonResponse
    {
        $this->assertCandidateEnvironment();
        $payload = $request->validated();

        try {
            return DB::transaction(function () use ($request, $id, $payload): JsonResponse {
                $checksheet = PmChecksheet::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->lockCandidateScheduleRows($checksheet, $payload['selected_machine_ids']);
                $scheduleAlreadyAppliedMachineIds = $checksheet->machineAssignments()
                    ->pluck('machine_id')
                    ->map(fn ($machineId): int => (int) $machineId)
                    ->all();

                $result = $this->scheduleUpdateService->apply(
                    $checksheet,
                    $payload['schedule'],
                    $payload['token'],
                    $payload['selected_machine_ids'],
                );

                if (($result['status'] ?? null) !== 'applied') {
                    return response()->json($result, 422);
                }

                $this->checksheetService->update($request, $checksheet, $payload, $scheduleAlreadyAppliedMachineIds);

                return response()->json($result);
            });
        } catch (StalePreviewException) {
            $checksheet = $this->findChecksheet($id);
            $freshPreview = $this->scheduleUpdateService->preview(
                $checksheet,
                $payload['schedule'],
                $payload['selected_machine_ids'],
            );

            return response()->json([
                'status' => 'stale',
                'message' => 'Preview sudah kedaluwarsa karena state checksheet atau pilihan mesin berubah. Tinjau preview terbaru dan konfirmasi ulang.',
                'preview' => $freshPreview,
            ], 409);
        }
    }

    /**
     * Lock rows in the same checksheet → machines → schedules order used by Apply.
     *
     * @param  list<int|string>  $selectedMachineIds
     */
    private function lockCandidateScheduleRows(PmChecksheet $checksheet, array $selectedMachineIds): void
    {
        $existingMachineIds = $checksheet->machineAssignments()
            ->pluck('machine_id')
            ->map(fn ($machineId): int => (int) $machineId)
            ->all();
        $machineIds = array_values(array_unique(array_map('intval', [...$existingMachineIds, ...$selectedMachineIds])));
        sort($machineIds, SORT_NUMERIC);

        $lockedMachines = Machine::query()
            ->whereIn('id', $machineIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($selectedMachineIds as $machineId) {
            $machine = $lockedMachines->get((int) $machineId);
            if ($machine === null
                || $machine->deleted_at !== null
                || (! $machine->is_active && ! in_array((int) $machineId, $existingMachineIds, true))
            ) {
                throw ValidationException::withMessages([
                    'selected_machine_ids' => 'Mesin tidak valid atau sudah nonaktif.',
                ]);
            }
        }

        PmSchedule::query()
            ->withTrashed()
            ->whereHas('checksheetMachine', fn ($query) => $query->where('pm_checksheet_id', $checksheet->id))
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Candidate PUT can reuse persisted schedule settings; the existing Apply endpoint validates its preview fingerprint before writing them.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertCandidateScheduleConfigurationWasApplied(PmChecksheet $checksheet, array $payload): void
    {
        $schedulePayload = $payload['schedule'];
        $selectedMachineIds = array_map('intval', $payload['selected_machine_ids']);
        $assignments = PmChecksheetMachine::query()
            ->where('pm_checksheet_id', $checksheet->id)
            ->whereIn('machine_id', $selectedMachineIds)
            ->get()
            ->keyBy('machine_id');
        $currentSchedules = PmSchedule::query()
            ->currentEra()
            ->whereHas('checksheetMachine', fn ($query) => $query->where('pm_checksheet_id', $checksheet->id))
            ->get();
        $currentSchedulesByAssignment = $currentSchedules->keyBy('pm_checksheet_machine_id');
        $endedAssignmentIds = PmSchedule::query()
            ->withTrashed()
            ->whereIn('pm_checksheet_machine_id', $assignments->pluck('id'))
            ->where('lifecycle_status', 'ended')
            ->pluck('pm_checksheet_machine_id')
            ->map(fn ($assignmentId): int => (int) $assignmentId);

        foreach ($selectedMachineIds as $machineId) {
            $assignment = $assignments->get($machineId);
            if ($assignment === null
                || (! $currentSchedulesByAssignment->has($assignment->id)
                    && ! $endedAssignmentIds->contains((int) $assignment->id))
            ) {
                throw ValidationException::withMessages([
                    'wizard_payload.schedule' => 'Preview dan terapkan jadwal sebelum menyimpan perubahan checksheet.',
                ]);
            }
        }

        $frequencyType = $schedulePayload['frequency_type'];
        $expectedWeeklyDays = $frequencyType === 'weekly'
            ? array_map('intval', array_values($schedulePayload['weekly_days']))
            : null;
        $expectedMonthlyDay = $frequencyType === 'monthly' ? (int) $schedulePayload['monthly_day'] : null;
        $submittedOperationalFrom = $schedulePayload['operational_from'] ?? null;
        $expectedOperationalFrom = $submittedOperationalFrom === null || $submittedOperationalFrom === ''
            ? null
            : Carbon::parse($submittedOperationalFrom)->toDateString();

        foreach ($currentSchedules as $schedule) {
            $currentWeeklyDays = is_array($schedule->weekly_days)
                ? array_map('intval', array_values($schedule->weekly_days))
                : null;
            $operationalFromMatches = $expectedOperationalFrom === null
                || $schedule->operational_from?->toDateString() === $expectedOperationalFrom;

            if ($schedule->frequency_type !== $frequencyType
                || $currentWeeklyDays !== $expectedWeeklyDays
                || $schedule->monthly_day !== $expectedMonthlyDay
                || ! $operationalFromMatches
            ) {
                throw ValidationException::withMessages([
                    'wizard_payload.schedule' => 'Preview dan terapkan perubahan jadwal sebelum menyimpan checksheet.',
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function formProps(Request $request, ?PmChecksheet $checksheet): array
    {
        $isEdit = $checksheet !== null;
        $fallbackPayload = $checksheet ? $this->checksheetService->toWizardPayload($checksheet) : [];
        $id = $checksheet?->id;
        $oldInput = $request->session()->get('_old_input', []);
        $oldIsActive = array_key_exists('is_active', $oldInput) ? $oldInput['is_active'] : ($checksheet?->is_active ?? true);
        $normalizedIsActive = filter_var($oldIsActive, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return [
            'isEdit' => $isEdit,
            'checksheet' => [
                'id' => $id,
                'checksheet_code' => array_key_exists('checksheet_code', $oldInput) ? ($oldInput['checksheet_code'] ?? '') : ($checksheet?->checksheet_code ?? ''),
                'checksheet_name' => array_key_exists('checksheet_name', $oldInput) ? ($oldInput['checksheet_name'] ?? '') : ($checksheet?->checksheet_name ?? ''),
                'description' => array_key_exists('description', $oldInput) ? ($oldInput['description'] ?? '') : ($checksheet?->description ?? ''),
                'is_active' => $normalizedIsActive ?? (bool) ($checksheet?->is_active ?? true),
            ],
            'machines' => $this->availableMachines($checksheet)->map(fn ($machine): array => [
                'id' => (int) $machine->id,
                'code' => $machine->machine_code,
                'name' => $machine->machine_name,
                'location' => $machine->location?->location_name,
            ])->values(),
            'actions' => $this->availableActions($checksheet)->map(fn ($action): array => [
                'id' => (int) $action->id,
                'action_label' => $action->action_label,
                'is_active' => (bool) $action->is_active,
            ])->values(),
            'originalMachineIds' => array_map('intval', $fallbackPayload['selected_machine_ids'] ?? []),
            'originalSchedule' => $isEdit ? ($fallbackPayload['schedule'] ?? null) : null,
            'payload' => $this->oldPayload($request, $fallbackPayload),
            'indexUrl' => route('master-checksheet.index'),
            'submitUrl' => $isEdit ? route('master-checksheet.vue-candidate.update', $id) : route('master-checksheet.vue-candidate.store'),
            'showUrl' => $isEdit ? route('master-checksheet.show', $id) : null,
            'previewUrl' => $isEdit ? route('master-checksheet.vue-candidate.preview-schedule-update', $id) : null,
            'scheduleDatesUrl' => route('master-checksheet.vue-candidate.preview-schedule-dates'),
            'applyUrl' => $isEdit ? route('master-checksheet.vue-candidate.apply-schedule-update', $id) : null,
            'actionStoreUrl' => route('master-checksheet.actions.store'),
            'manageAi' => Gate::allows('manage_ai'),
            'notificationEndpoints' => [
                'index' => route('admin-notifications.index'),
                'readAll' => route('admin-notifications.read-all'),
                'readTemplate' => route('admin-notifications.read', ['notificationId' => '__ID__']),
                'fallback' => route('master-checksheet.index'),
            ],
        ];
    }

    private function assertCandidateEnvironment(): void
    {
        abort_unless(in_array(config('app.env'), ['local', 'testing', 'qa'], true), 404);
    }

    /** @return array<string, mixed> */
    private function oldPayload(Request $request, array $fallback): array
    {
        $raw = $request->old('wizard_payload');
        if (! is_string($raw)) {
            return $fallback;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $fallback;
    }
}
