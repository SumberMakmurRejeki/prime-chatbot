<?php

namespace App\Services\PM;

use App\Models\Machine;
use App\Models\PmChecksheetPart;
use App\Models\PmChecksheetStandard;
use App\Models\PmExecution;
use App\Models\PmExecutionItem;
use App\Models\PmExecutionItemActionResult;
use App\Models\PmExecutionMedia;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\Auth\ActivityLogService;
use App\Services\Master\PmActionService;
use App\Services\Notification\AdminNotificationService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PmExecutionService
{
    /**
     * BR-PM-EXEC-004: pesan penolakan tunggal untuk aksi Operator tanpa execution canonical.
     */
    private const MESSAGE_PM_NOT_STARTED = 'PM belum dimulai. Mulai PM terlebih dahulu.';

    public function __construct(
        protected PmWarningService $warningService,
        protected PmExecutionHistoryService $historyService,
        protected ActivityLogService $activityLogService,
        protected AdminNotificationService $notificationService,
        protected PmActionService $actionService,
        protected LifecyclePolicy $lifecyclePolicy,
    ) {}

    /**
     * Definisi yang ditampilkan Executor: snapshot beku bila execution sudah
     * dimulai, atau definisi master untuk occurrence yang belum pernah dimulai.
     * Pada execution legacy (definition_snapshot_at null) kunci input tetap
     * id standar master, karena baris hasil lama memang dipetakan per standar.
     *
     * @return array{
     *   schedule_date: PmScheduleDate,
     *   execution: ?PmExecution,
     *   checksheet_code: string,
     *   checksheet_name: string,
     *   parts: array<int, array{part_id: ?int, name: string, note: ?string, note_key: string, items: array<int, array<string, mixed>>}>,
     *   item_key_mode: 'snapshot'|'legacy',
     *   media_by_part: array<int, Collection<int, PmExecutionMedia>>
     * }
     *
     * @throws ValidationException
     */
    public function getExecutorContext(Machine $machine): array
    {
        $scheduleDate = $this->findScheduleDateForExecutor($machine);
        $execution = $this->findCanonicalExecution($machine, $scheduleDate, false);

        // Execution soft-deleted tetap identity histori dan bukan pekerjaan live;
        // jangan expose kembali sebagai checklist/editable executor.
        if ($execution?->trashed()) {
            throw ValidationException::withMessages([
                'machine' => 'Occurrence ini memiliki execution histori yang sudah dihapus; replacement tidak diizinkan.',
            ]);
        }

        // BR-PM-SCH-001: occurrence future hanya boleh dibuka bila execution
        // canonical sudah ada (continuation); start baru menunggu tanggal jadwal.
        if (! $execution && $this->isFutureOccurrence($scheduleDate)) {
            throw ValidationException::withMessages([
                'machine' => 'PM terjadwal belum dapat dimulai sebelum tanggal jadwalnya.',
            ]);
        }

        // Header checksheet berasal dari identity occurrence, bukan master hidup.
        $scheduleDate->loadMissing('schedule.checksheetMachine.checksheet');
        $checksheetMachine = $scheduleDate->schedule?->checksheetMachine;

        if (! $checksheetMachine) {
            throw ValidationException::withMessages([
                'machine' => 'Relasi checksheet mesin tidak ditemukan.',
            ]);
        }

        $itemKeyMode = $execution && $execution->definition_snapshot_at !== null ? 'snapshot' : 'legacy';
        $parts = $this->executorDefinitionParts($scheduleDate, $execution, $itemKeyMode);

        if ($parts === []) {
            throw ValidationException::withMessages([
                'machine' => 'Part atau standard checksheet belum tersedia.',
            ]);
        }

        $mediaByPart = $execution
            ? $execution->media()
                ->orderByDesc('id')
                ->get()
                ->groupBy('pm_checksheet_part_id')
                ->all()
            : [];

        return [
            'schedule_date' => $scheduleDate,
            'execution' => $execution,
            'checksheet_code' => (string) ($checksheetMachine->checksheet?->checksheet_code ?? '-'),
            'checksheet_name' => (string) ($checksheetMachine->checksheet?->checksheet_name ?? '-'),
            'parts' => $parts,
            'item_key_mode' => $itemKeyMode,
            'media_by_part' => $mediaByPart,
        ];
    }

    /**
     * @return array<int, array{part_id: ?int, name: string, note: ?string, note_key: string, items: array<int, array<string, mixed>>}>
     */
    private function executorDefinitionParts(PmScheduleDate $scheduleDate, ?PmExecution $execution, string $itemKeyMode): array
    {
        if ($execution && $itemKeyMode === 'snapshot') {
            // Snapshot beku: satu-satunya sumber definisi adalah pm_execution_items.
            $items = PmExecutionItem::query()
                ->where('pm_execution_id', $execution->id)
                ->with('actionResults')
                ->orderBy('id')
                ->get();

            $rows = $items->map(fn (PmExecutionItem $item): array => [
                'part_id' => $item->pm_checksheet_part_id !== null ? (int) $item->pm_checksheet_part_id : null,
                'part_name' => (string) $item->part_name_snapshot,
                'key' => (string) $item->id,
                'name' => (string) $item->standard_name_snapshot,
                'input_type' => (string) $item->input_type_snapshot,
                'selection_mode' => $item->effectiveSelectionMode(),
                'action_options' => $this->normalizedActionOptions($item->action_options_snapshot),
                'is_required' => (bool) $item->is_required_snapshot,
                'target_value' => $item->target_value_snapshot,
                'min_value' => $item->min_value_snapshot,
                'max_value' => $item->max_value_snapshot,
                'unit' => $item->unit_snapshot,
                'action_value' => $item->effectiveSelectionMode() === 'multiple'
                    ? ($item->actionResults->isNotEmpty() ? null : $item->action_value)
                    : ($item->action_value !== null ? strtoupper((string) $item->action_value) : null),
                'selected_action_values' => $item->effectiveSelectionMode() === 'multiple'
                    ? $this->presentedSelectedActionValues($item) : [],
                'number_value' => $item->number_value,
                'warning_message' => $item->warning_message,
                'note' => $item->note,
            ])->all();

            return $this->groupExecutorDefinitionRows($rows);
        }

        // Definisi belum beku: occurrence belum dimulai, atau execution legacy hasil
        // sebelum fitur snapshot. Kunci input mengikuti id standar master.
        $existingItems = $execution
            ? PmExecutionItem::query()
                ->where('pm_execution_id', $execution->id)
                ->orderBy('id')
                ->get()
                ->keyBy('pm_checksheet_standard_id')
                ->all()
            : [];

        $rows = [];
        foreach ($this->activeChecksheetParts($scheduleDate) as $part) {
            foreach ($part->standards as $standard) {
                $item = $existingItems[$standard->id] ?? null;
                $rows[] = [
                    'part_id' => (int) $part->id,
                    'part_name' => (string) $part->part_name,
                    'key' => (string) $standard->id,
                    'name' => (string) $standard->standard_name,
                    'input_type' => (string) $standard->input_type,
                    'selection_mode' => $execution
                        ? ($item?->effectiveSelectionMode() ?? ($standard->input_type === 'action' ? 'single' : null))
                        : $standard->effectiveSelectionMode(),
                    'action_options' => $standard->input_type === 'action' ? $this->definitionActionOptions($standard) : [],
                    'is_required' => (bool) $standard->is_required,
                    'target_value' => $standard->target_value,
                    'min_value' => $standard->min_value,
                    'max_value' => $standard->max_value,
                    'unit' => $standard->unit,
                    'action_value' => $item?->action_value !== null ? strtoupper((string) $item->action_value) : null,
                    'number_value' => $item?->number_value,
                    'warning_message' => $item?->warning_message,
                    'note' => $item?->note,
                ];
            }
        }

        return $this->groupExecutorDefinitionRows($rows);
    }

    /**
     * Kelompokkan baris definisi menjadi part berurutan. Kunci catatan part adalah
     * id part master bila ada, sehingga form catatan tetap kompatibel dengan
     * jalur review yang menyimpan catatan per pm_checksheet_part_id.
     *
     * @param  array<int, array{part_id: ?int, part_name: string, key: string, name: string, input_type: string, action_options: array<int, string>, is_required: bool, target_value: mixed, min_value: mixed, max_value: mixed, unit: ?string, action_value: ?string, number_value: mixed, warning_message: ?string, note: ?string}>  $rows
     * @return array<int, array{part_id: ?int, name: string, note: ?string, note_key: string, items: array<int, array<string, mixed>>}>
     */
    private function groupExecutorDefinitionRows(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $index = count($groups) - 1;
            $current = $index >= 0 ? $groups[$index] : null;

            if ($current === null
                || $current['part_id'] !== $row['part_id']
                || $current['name'] !== $row['part_name']
            ) {
                $groups[] = [
                    'part_id' => $row['part_id'],
                    'name' => $row['part_name'],
                    'note' => $row['note'],
                    'note_key' => $row['part_id'] !== null ? (string) $row['part_id'] : 'item'.$row['key'],
                    'items' => [],
                ];
                $index = count($groups) - 1;
            }

            unset($row['part_id'], $row['part_name'], $row['note']);
            $groups[$index]['items'][] = $row;
        }

        return $groups;
    }

    /**
     * @param  array<int, mixed>|string|null  $options
     * @return array<int, string>
     */
    private function normalizedActionOptions(array|string|null $options): array
    {
        if (is_string($options)) {
            $options = json_decode($options, true, 512, JSON_THROW_ON_ERROR);
        }

        return collect($options ?? [])
            ->map(fn ($option): string => strtoupper((string) $option))
            ->values()
            ->all();
    }

    /**
     * Hasil multiple tersimpan dipetakan ke label opsi beku yang benar-benar
     * ditampilkan, memakai identitas kanonik Action yang sama dengan validasi
     * SET/CLEAR. Identitas ambigu atau tidak dikenal sengaja tidak dipetakan
     * agar tidak ada posisi terpilih yang ditebak; snapshot historis tidak
     * pernah diubah.
     *
     * @return array<int, string>
     */
    private function presentedSelectedActionValues(PmExecutionItem $item): array
    {
        if ($item->actionResults->isEmpty()) {
            return [];
        }

        $uniqueByIdentity = [];
        foreach ($this->normalizedActionOptions($item->action_options_snapshot) as $displayLabel) {
            $identity = $this->actionService->normalizeLabel($displayLabel);
            $uniqueByIdentity[$identity] = array_key_exists($identity, $uniqueByIdentity) ? null : $displayLabel;
        }

        return $item->actionResults
            ->map(fn (PmExecutionItemActionResult $result): ?string => $uniqueByIdentity[$this->actionService->normalizeLabel((string) $result->selected_label_snapshot)] ?? null)
            ->filter(static fn (?string $label): bool => $label !== null)
            ->values()
            ->all();
    }

    /**
     * BR-PM-EXEC-004: Simpan Draft hanya memutasi execution canonical yang sudah
     * dimulai lewat aksi Mulai PM; aksi ini tidak pernah membuat execution baru.
     *
     * @param  array<int|string, mixed>  $actionValues
     * @param  array<int|string, mixed>  $numberValues
     * @param  array<int|string, mixed>  $partNotes
     * @param  array<int|string, mixed>  $clearValues
     *
     * @throws ValidationException
     */
    public function saveDraft(
        Request $request,
        Machine $machine,
        User $operator,
        array $actionValues,
        array $numberValues,
        array $partNotes,
        int $expectedRevision,
        array $clearValues = [],
    ): PmExecution {
        return $this->mutateOperatorResult($request, $machine, $operator, $actionValues, $numberValues, $partNotes, $expectedRevision, false, $clearValues);
    }

    /**
     * @param  array<int|string, mixed>  $actionValues
     * @param  array<int|string, mixed>  $numberValues
     * @param  array<int|string, mixed>  $partNotes
     * @param  array<int|string, mixed>  $clearValues
     *
     * @throws ValidationException
     */
    public function submit(
        Request $request,
        Machine $machine,
        User $operator,
        array $actionValues,
        array $numberValues,
        array $partNotes,
        int $expectedRevision,
        array $clearValues = [],
    ): PmExecution {
        return $this->mutateOperatorResult($request, $machine, $operator, $actionValues, $numberValues, $partNotes, $expectedRevision, true, $clearValues);
    }

    /**
     * The sole result-writer boundary. Parent and canonical rows remain locked
     * through validation, item replacement, audit, lifecycle, and revision.
     *
     * BR-PM-EXEC-004: resolver ini tidak membuat execution; aksi Mulai PM adalah
     * satu-satunya jalur creation, sehingga hasil Operator tanpa execution
     * canonical ditolak sebelum mutasi apa pun.
     *
     * @param  array<int|string, mixed>  $actionValues
     * @param  array<int|string, mixed>  $numberValues
     * @param  array<int|string, mixed>  $partNotes
     * @param  array<int|string, mixed>  $clearValues
     */
    private function mutateOperatorResult(
        Request $request,
        Machine $machine,
        User $operator,
        array $actionValues,
        array $numberValues,
        array $partNotes,
        int $expectedRevision,
        bool $submitting,
        array $clearValues,
    ): PmExecution {
        return DB::transaction(function () use ($request, $machine, $operator, $actionValues, $numberValues, $partNotes, $expectedRevision, $submitting, $clearValues): PmExecution {
            $lockedMachine = Machine::query()->whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $scheduleDate = $this->findScheduleDateForExecutor($lockedMachine, true, true);
            $execution = $this->findCanonicalExecution($lockedMachine, $scheduleDate, true);

            if (! $execution) {
                throw ValidationException::withMessages([
                    'machine' => self::MESSAGE_PM_NOT_STARTED,
                ]);
            }

            return $this->mutateLockedExecution(
                request: $request,
                machine: $lockedMachine,
                operator: $operator,
                scheduleDate: $scheduleDate,
                execution: $execution,
                actionValues: $actionValues,
                numberValues: $numberValues,
                partNotes: $partNotes,
                expectedRevision: $expectedRevision,
                clearValues: $clearValues,
                submitting: $submitting,
            );
        });
    }

    private function mutateLockedExecution(
        Request $request,
        Machine $machine,
        User $operator,
        PmScheduleDate $scheduleDate,
        PmExecution $execution,
        array $actionValues,
        array $numberValues,
        array $partNotes,
        int $expectedRevision,
        bool $submitting,
        array $clearValues,
    ): PmExecution {
        $this->assertExpectedRevision($execution, $expectedRevision);
        $this->assertStartableExecution($execution);

        // Execution ber-snapshot hanya memakai definisi item beku; hasil multiple berada di child rows.
        if ($execution->definition_snapshot_at !== null) {
            return $this->mutateSnapshotLockedExecution(
                request: $request,
                machine: $machine,
                operator: $operator,
                scheduleDate: $scheduleDate,
                execution: $execution,
                actionValues: $actionValues,
                numberValues: $numberValues,
                clearValues: $clearValues,
                partNotes: $partNotes,
                submitting: $submitting,
            );
        }

        if ($clearValues !== []) {
            throw ValidationException::withMessages(['execution_clear' => 'Penanda hapus hanya berlaku pada Action multiple bersnapshot.']);
        }

        $parts = PmChecksheetPart::query()
            ->where('pm_checksheet_machine_id', $scheduleDate->schedule?->checksheetMachine?->id)
            ->where('is_active', true)
            ->with(['standards' => fn ($query) => $query->where('is_active', true)->orderBy('id')])
            ->orderBy('id')
            ->get();

        if ($parts->isEmpty()) {
            throw ValidationException::withMessages(['machine' => 'Part atau standard checksheet belum tersedia.']);
        }

        $oldItems = PmExecutionItem::query()
            ->where('pm_execution_id', $execution->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('pm_checksheet_standard_id');
        [$rows, $changes, $changed] = $this->buildCandidateRows($execution, $parts, $actionValues, $numberValues, $partNotes, $oldItems, $submitting ? 'Operator: Submit PM' : 'Operator: Simpan Draft', $submitting);

        if ($submitting && $execution->status !== 'in_progress') {
            throw ValidationException::withMessages(['machine' => 'PM sudah disubmit dan menunggu review.']);
        }
        if ($changed) {
            $this->persistExecutionItems($execution, $rows);
        }

        if ($submitting) {
            $execution->forceFill(['status' => 'waiting_review', 'submitted_at' => now()])->save();
            $scheduleDate->forceFill(['status' => 'waiting_review', 'status_changed_at' => now()])->save();
            $this->ensureNextScheduleDateExists($scheduleDate);
            $changes[] = [
                'field_name' => 'Status PM',
                'old_value' => 'in_progress',
                'new_value' => 'waiting_review',
                'change_note' => 'Operator: Submit PM',
            ];
            $this->notificationService->createUnique(
                notificationType: 'pm_waiting_review',
                title: sprintf('Menunggu Review: Hasil PM Mesin %s', $machine->machine_name),
                message: sprintf('Hai, proses Preventive Maintenance untuk mesin %s telah selesai dikerjakan. Mohon segera melakukan review agar proses ini dapat segera diselesaikan. Terima kasih atas kerja samanya!', $machine->machine_name),
                relatedTable: 'pm_executions', relatedId: $execution->id, targetUrl: '/pm/review/'.$execution->id,
                data: ['machine_id' => $machine->id, 'machine_code' => $machine->machine_code],
            );
            $this->activityLogService->log(request: $request, moduleName: 'pm_executor', action: 'submit_pm', description: sprintf('Submit PM untuk mesin %s', $machine->machine_code), tableName: 'pm_executions', recordId: $execution->id);
        }
        if ($changes !== []) {
            $this->historyService->recordMany($execution, $operator, $changes);
        }
        if ($changed || $submitting) {
            $execution->forceFill(['revision' => (int) $execution->revision + 1])->save();
        }

        return $execution->fresh(['items', 'media']) ?? $execution;
    }

    /**
     * Mutasi hasil execution snapshot tanpa membaca definisi master dan tanpa
     * menghapus baris item, sehingga ID item tetap stabil untuk media dan histori.
     *
     * @throws ValidationException
     */
    private function mutateSnapshotLockedExecution(
        Request $request,
        Machine $machine,
        User $operator,
        PmScheduleDate $scheduleDate,
        PmExecution $execution,
        array $actionValues,
        array $numberValues,
        array $partNotes,
        bool $submitting,
        array $clearValues,
    ): PmExecution {
        $items = PmExecutionItem::query()
            ->where('pm_execution_id', $execution->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['machine' => 'Part atau standard checksheet belum tersedia.']);
        }

        $allowedItemIds = $items->pluck('id')->map(static fn (int $id): string => (string) $id)->all();
        $submittedItemIds = collect(array_merge(array_keys($actionValues), array_keys($numberValues), array_keys($clearValues)))
            ->map(static fn (int|string $id): string => (string) $id)->unique()->all();
        if (array_diff($submittedItemIds, $allowedItemIds) !== []) {
            throw ValidationException::withMessages([
                'machine' => 'Item checklist tidak valid untuk execution ini. Muat ulang data terbaru.',
            ]);
        }

        // Lock parent item lebih dulu, kemudian hasil child, agar Draft/Submit tidak berbalapan.
        $resultsByItem = PmExecutionItemActionResult::query()
            ->whereIn('pm_execution_item_id', $items->modelKeys())
            ->orderBy('pm_execution_item_id')->orderBy('option_position_snapshot')
            ->lockForUpdate()->get()->groupBy('pm_execution_item_id');
        $changes = [];
        $changed = false;
        $candidates = [];

        foreach ($items as $item) {
            $existingRows = $resultsByItem->get($item->id, collect());
            $oldState = $this->candidateState($item);
            $isMultiple = $item->effectiveSelectionMode() === 'multiple';
            if ($isMultiple) {
                $oldState['action'] = $existingRows->isNotEmpty()
                    ? $existingRows->pluck('selected_label_snapshot')->all()
                    : ($item->action_value !== null && $item->action_value !== '' ? $item->action_value : null);
            }
            $newState = $oldState;
            $newRows = null;
            $hasAction = array_key_exists($item->id, $actionValues);
            $hasNormalizedResults = $existingRows->isNotEmpty();
            if ($isMultiple && $hasNormalizedResults && $item->action_value !== null) {
                $changed = true;
            }
            $hasNumber = array_key_exists($item->id, $numberValues);
            $hasClear = array_key_exists($item->id, $clearValues);

            if ($item->input_type_snapshot === 'action') {
                if ($hasNumber || ($hasClear && (! $isMultiple || $hasAction))) {
                    throw ValidationException::withMessages(["execution_action.{$item->id}" => 'Bentuk perubahan Action tidak valid.']);
                }
                if ($isMultiple) {
                    if ($hasClear) {
                        $newState['action'] = null;
                        $newRows = [];
                    } elseif ($hasAction) {
                        $newRows = $this->actionService->resolveFrozenChoices($item->action_options_snapshot ?? [], $actionValues[$item->id], "execution_action.{$item->id}");
                        $newState['action'] = array_column($newRows, 'selected_label_snapshot');
                    }
                } elseif ($hasAction) {
                    $value = $actionValues[$item->id];
                    if ($value !== null && ! is_string($value)) {
                        throw ValidationException::withMessages(["execution_action.{$item->id}" => 'Action single harus berupa satu pilihan.']);
                    }
                    $action = strtoupper(trim((string) $value));
                    if ($action !== '' && mb_strlen($action, 'UTF-8') > 50) {
                        throw ValidationException::withMessages(["execution_action.{$item->id}" => 'Nilai Action melebihi batas penyimpanan.']);
                    }
                    $newState['action'] = $action === '' ? null : $action;
                    if ($newState['action'] !== null
                        && ! in_array($newState['action'], $this->normalizedActionOptions($item->action_options_snapshot), true)
                    ) {
                        throw ValidationException::withMessages(["execution_action.{$item->id}" => sprintf('Pilihan action "%s" tidak valid.', $item->standard_name_snapshot)]);
                    }
                }
                if ($submitting && $item->is_required_snapshot && (
                    $newState['action'] === null
                    || $newState['action'] === []
                    || (is_string($newState['action']) && trim($newState['action']) === '')
                )) {
                    throw ValidationException::withMessages(["execution_action.{$item->id}" => sprintf('Standard "%s" wajib diisi.', $item->standard_name_snapshot)]);
                }
                $newState['number'] = null;
            } else {
                if ($hasAction || $hasClear) {
                    throw ValidationException::withMessages(["execution_number.{$item->id}" => 'Bentuk perubahan angka tidak valid.']);
                }
                if ($hasNumber) {
                    $value = $numberValues[$item->id];
                    if ($value !== null && $value !== '' && ! is_numeric($value)) {
                        throw ValidationException::withMessages(["execution_number.{$item->id}" => 'Hasil harus berupa angka.']);
                    }
                    $newState['number'] = $value === null || $value === ''
                        ? null : number_format((float) $value, 2, '.', '');
                }
                if ($submitting && $item->is_required_snapshot && $newState['number'] === null) {
                    throw ValidationException::withMessages(["execution_number.{$item->id}" => sprintf('Standard "%s" wajib diisi angka.', $item->standard_name_snapshot)]);
                }
                $newState['action'] = null;
            }

            $warning = $this->warningService->evaluate([
                'input_type' => (string) $item->input_type_snapshot,
                'target_value' => $item->target_value_snapshot,
                'min_value' => $item->min_value_snapshot,
                'max_value' => $item->max_value_snapshot,
                'unit' => $item->unit_snapshot,
            ], $newState['number'] === null ? null : (float) $newState['number']);
            $newState['warning'] = (bool) $warning['is_warning'];
            $newState['warning_message'] = $warning['warning_message'] !== null ? trim((string) $warning['warning_message']) : null;
            $noteKey = $item->pm_checksheet_part_id !== null ? (string) $item->pm_checksheet_part_id : 'item'.$item->id;
            if (array_key_exists($noteKey, $partNotes)) {
                $note = trim((string) $partNotes[$noteKey]);
                $newState['note'] = $note === '' ? null : $note;
            }

            if ($oldState !== $newState) {
                $changed = true;
                // Catatan Part dicatat sendiri; jangan buat histori hasil JSON palsu.
                if (! $isMultiple || $oldState['action'] !== $newState['action']) {
                    $changes[] = [
                        'field_name' => $this->historyService->formatHistoryFieldName('result', $item->pm_checksheet_part_id, $item->pm_checksheet_standard_id, $item->id, $item->part_name_snapshot, $item->standard_name_snapshot),
                        'old_value' => $this->resultDisplay($oldState),
                        'new_value' => $this->resultDisplay($newState),
                        'change_note' => $submitting ? 'Operator: Submit PM' : 'Operator: Simpan Draft',
                    ];
                }
            }
            if ($item->note !== $newState['note']) {
                $changes[] = [
                    'field_name' => $this->historyService->formatHistoryFieldName('part_note', $item->pm_checksheet_part_id, null, $item->id, $item->part_name_snapshot, null),
                    'old_value' => $item->note,
                    'new_value' => $newState['note'],
                    'change_note' => $submitting ? 'Operator: Submit PM' : 'Operator: Simpan Draft',
                ];
            }
            $candidates[] = [$item, $newState, $newRows, $isMultiple, $oldState['action'] !== $newState['action'], $hasNormalizedResults];
        }

        // Semua requiredness diperiksa terhadap kandidat merge sebelum satu pun hasil berubah.
        foreach ($candidates as [$item, $newState, $newRows, $isMultiple, $actionChanged, $hasNormalizedResults]) {
            $item->forceFill([
                'action_value' => $isMultiple
                    ? (($newRows !== null || $hasNormalizedResults) ? null : $item->action_value)
                    : $newState['action'],
                'number_value' => $newState['number'],
                'is_warning' => $newState['warning'],
                'warning_message' => $newState['warning_message'],
                'note' => $newState['note'],
            ]);
            if ($item->isDirty()) {
                $item->save();
            }
            if ($newRows !== null && $actionChanged) {
                $item->actionResults()->delete();
                if ($newRows !== []) {
                    $item->actionResults()->createMany($newRows);
                }
            }
        }

        if ($submitting && $execution->status !== 'in_progress') {
            throw ValidationException::withMessages(['machine' => 'PM sudah disubmit dan menunggu review.']);
        }

        if ($submitting) {
            $execution->forceFill(['status' => 'waiting_review', 'submitted_at' => now()])->save();
            $scheduleDate->forceFill(['status' => 'waiting_review', 'status_changed_at' => now()])->save();
            $this->ensureNextScheduleDateExists($scheduleDate);
            $changes[] = ['field_name' => 'Status PM', 'old_value' => 'in_progress', 'new_value' => 'waiting_review', 'change_note' => 'Operator: Submit PM'];
            $this->notificationService->createUnique(
                notificationType: 'pm_waiting_review',
                title: sprintf('Menunggu Review: Hasil PM Mesin %s', $machine->machine_name),
                message: sprintf('Hai, proses Preventive Maintenance untuk mesin %s telah selesai dikerjakan. Mohon segera melakukan review agar proses ini dapat segera diselesaikan. Terima kasih atas kerja samanya!', $machine->machine_name),
                relatedTable: 'pm_executions', relatedId: $execution->id, targetUrl: '/pm/review/'.$execution->id,
                data: ['machine_id' => $machine->id, 'machine_code' => $machine->machine_code],
            );
            $this->activityLogService->log(request: $request, moduleName: 'pm_executor', action: 'submit_pm', description: sprintf('Submit PM untuk mesin %s', $machine->machine_code), tableName: 'pm_executions', recordId: $execution->id);
        }

        if ($changes !== []) {
            $this->historyService->recordMany($execution, $operator, $changes);
        }
        if ($changed || $submitting) {
            $execution->forceFill(['revision' => (int) $execution->revision + 1])->save();
        }

        return $execution->fresh(['items', 'media']) ?? $execution;
    }

    private function assertExpectedRevision(PmExecution $execution, int $expectedRevision): void
    {
        if ((int) $execution->revision !== $expectedRevision) {
            throw new StalePmExecutionException;
        }
    }

    /**
     * @param  array<int|string, mixed>  $actionValues
     * @param  array<int|string, mixed>  $numberValues
     * @param  array<int|string, mixed>  $partNotes
     *
     * @throws ValidationException
     */
    protected function persistExecutionItems(PmExecution $execution, array $rows): void
    {
        PmExecutionItem::query()->where('pm_execution_id', $execution->id)->delete();
        if ($rows !== []) {
            PmExecutionItem::query()->insert($this->definitionRowsForInsert($rows));
        }
    }

    private function buildCandidateRows(PmExecution $execution, Collection $parts, array $actionValues, array $numberValues, array $partNotes, Collection $oldItems, string $changeNote, bool $submitting): array
    {
        $rows = [];
        $changes = [];
        $changedPartNotes = [];

        foreach ($parts as $part) {
            foreach ($part->standards as $standard) {
                $old = $oldItems->get($standard->id);
                $action = $old?->action_value;
                $number = $old?->number_value === null ? null : number_format((float) $old->number_value, 2, '.', '');
                if (array_key_exists($standard->id, $actionValues)) {
                    $value = $actionValues[$standard->id];
                    if ($standard->input_type !== 'action' || ($value !== null && ! is_string($value))) {
                        throw ValidationException::withMessages(["execution_action.{$standard->id}" => 'Bentuk perubahan Action tidak valid.']);
                    }
                    $action = strtoupper(trim((string) $value)) ?: null;
                    if ($action !== null && mb_strlen($action, 'UTF-8') > 50) {
                        throw ValidationException::withMessages(["execution_action.{$standard->id}" => 'Nilai Action melebihi batas penyimpanan.']);
                    }
                }
                if (array_key_exists($standard->id, $numberValues)) {
                    $value = $numberValues[$standard->id];
                    if ($standard->input_type === 'action' || ($value !== null && $value !== '' && ! is_numeric($value))) {
                        throw ValidationException::withMessages(["execution_number.{$standard->id}" => 'Bentuk perubahan angka tidak valid.']);
                    }
                    $number = $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
                }

                if ($standard->input_type === 'action') {
                    $options = collect($standard->action_options ?? [])->map(fn ($option) => strtoupper((string) $option))->all();
                    if ($submitting && $standard->is_required && ($action === null || trim($action) === '')) {
                        throw ValidationException::withMessages(["execution_action.{$standard->id}" => sprintf('Standard "%s" wajib diisi.', $standard->standard_name)]);
                    }
                    if (array_key_exists($standard->id, $actionValues) && $action && ! in_array($action, $options, true)) {
                        throw ValidationException::withMessages(["execution_action.{$standard->id}" => sprintf('Pilihan action "%s" tidak valid.', $standard->standard_name)]);
                    }
                    $number = null;
                }

                if (in_array($standard->input_type, ['number', 'range'], true) && $submitting && $standard->is_required && $number === null) {
                    throw ValidationException::withMessages(["execution_number.{$standard->id}" => sprintf('Standard "%s" wajib diisi angka.', $standard->standard_name)]);
                }

                $warning = $this->warningService->evaluate([
                    'input_type' => $standard->input_type,
                    'target_value' => $standard->target_value,
                    'min_value' => $standard->min_value,
                    'max_value' => $standard->max_value,
                    'unit' => $standard->unit,
                ], $number === null ? null : (float) $number);
                $note = array_key_exists($part->id, $partNotes)
                    ? (trim((string) $partNotes[$part->id]) ?: null)
                    : $old?->note;
                $rows[] = [
                    'pm_execution_id' => $execution->id,
                    'pm_checksheet_part_id' => $part->id,
                    'pm_checksheet_standard_id' => $standard->id,
                    'part_name_snapshot' => $part->part_name,
                    'standard_name_snapshot' => $standard->standard_name,
                    'input_type_snapshot' => $standard->input_type,
                    'action_options_snapshot' => $standard->action_options ? json_encode($standard->action_options, JSON_THROW_ON_ERROR) : null,
                    'target_value_snapshot' => $standard->target_value,
                    'min_value_snapshot' => $standard->min_value,
                    'max_value_snapshot' => $standard->max_value,
                    'unit_snapshot' => $standard->unit,
                    'action_value' => $action,
                    'number_value' => $number,
                    'is_warning' => $warning['is_warning'],
                    'warning_message' => $warning['warning_message'],
                    'note' => $note,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $oldState = $this->candidateState($old);
                $newState = [
                    'action' => $action,
                    'number' => $number,
                    'warning' => (bool) $warning['is_warning'],
                    'warning_message' => $warning['warning_message'] !== null ? trim((string) $warning['warning_message']) : null,
                    'note' => $note,
                ];
                if ($oldState !== $newState) {
                    $changes[] = [
                        'field_name' => $this->historyService->formatHistoryFieldName('result', $old?->pm_checksheet_part_id ?? $part->id, $old?->pm_checksheet_standard_id ?? $standard->id, $old?->id, $old?->part_name_snapshot ?? $part->part_name, $old?->standard_name_snapshot ?? $standard->standard_name),
                        'old_value' => $this->resultDisplay($oldState),
                        'new_value' => $this->resultDisplay($newState),
                        'change_note' => $changeNote,
                    ];
                }
                if (! array_key_exists($part->id, $changedPartNotes) && ($old?->note ?? null) !== $note) {
                    $changedPartNotes[$part->id] = [$old, $part, $old?->note, $note];
                }
            }
        }

        foreach ($changedPartNotes as [$old, $part, $oldNote, $newNote]) {
            $changes[] = [
                'field_name' => $this->historyService->formatHistoryFieldName('part_note', $old?->pm_checksheet_part_id ?? $part->id, null, $old?->id, $old?->part_name_snapshot ?? $part->part_name, null),
                'old_value' => $oldNote,
                'new_value' => $newNote,
                'change_note' => $changeNote,
            ];
        }

        return [$rows, $changes, $changes !== [] || $oldItems->isEmpty()];
    }

    private function candidateState(?PmExecutionItem $item): array
    {
        return [
            'action' => $item?->action_value,
            'number' => $item?->number_value === null ? null : number_format((float) $item->number_value, 2, '.', ''),
            'warning' => (bool) ($item?->is_warning ?? false),
            'warning_message' => $item?->warning_message !== null ? trim((string) $item->warning_message) : null,
            'note' => $item?->note,
        ];
    }

    private function resultDisplay(array $state): ?string
    {
        // Array multiple diserialisasi sebagai JSON agar koma di dalam satu label tidak ambigu.
        if (is_array($state['action'] ?? null)) {
            return json_encode($state['action'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $parts = array_filter([
            $state['action'] ?? null,
            $state['number'] ?? null,
            $state['warning_message'] ?? null,
            $state['note'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');

        return $parts === [] ? null : implode(' | ', $parts);
    }

    /**
     * Resolver tunggal urutan pemilihan occurrence Executor.
     * liveEraOnly=true membatasi kandidat start BARU pada Schedule live.
     */
    private function findScheduleDateOrNull(
        Machine $machine,
        bool $lock,
        bool $includeExistingExecution,
    ): ?PmScheduleDate {
        $findDate = function (array $statuses, bool $liveEraOnly = false) use ($machine, $lock): ?PmScheduleDate {
            $query = PmScheduleDate::query()
                ->where('machine_id', $machine->id)
                ->whereIn('status', $statuses)
                ->with(['schedule.checksheetMachine.checksheet'])
                ->orderBy('scheduled_date');

            // Kandidat start baru wajib berasal dari Schedule live; PAUSED/ENDED
            // bukan pekerjaan baru walaupun masih current era.
            if ($liveEraOnly) {
                $query->whereHas('schedule', function ($scheduleQuery): void {
                    $scheduleQuery->where('lifecycle_status', 'active');
                });
            }

            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->first();
        };

        // Continuation: occurrence in_progress paling lama, era-agnostic karena
        // retirement dapat mengakhiri era setelah execution dimulai.
        $inProgress = $findDate(['in_progress']);
        if ($inProgress) {
            return $inProgress;
        }

        // Start baru: hanya bila Machine live; Schedule live difilter di query.
        $active = $this->lifecyclePolicy->isLive('machine', (string) $machine->lifecycle_status)
            ? $findDate(['scheduled', 'overdue'], true)
            : null;
        if ($active) {
            return $active;
        }

        if ($includeExistingExecution) {
            // Writer wajib menemukan occurrence yang punya histori meski status occurrence
            // stale; lifecycle execution kemudian menjadi sumber keputusan penolakan.
            $query = PmScheduleDate::query()
                ->where('machine_id', $machine->id)
                ->whereHas('canonicalExecution')
                ->with(['schedule.checksheetMachine.checksheet'])
                ->orderBy('scheduled_date');

            if ($lock) {
                $query->lockForUpdate();
            }

            $existingExecutionDate = $query->first();
            if ($existingExecutionDate) {
                return $existingExecutionDate;
            }
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function findScheduleDateForExecutor(Machine $machine, bool $lock = false, bool $includeExistingExecution = false): PmScheduleDate
    {
        $scheduleDate = $this->findScheduleDateOrNull($machine, $lock, $includeExistingExecution);

        if ($scheduleDate) {
            return $scheduleDate;
        }

        throw ValidationException::withMessages([
            'machine' => 'Tidak ada jadwal PM aktif untuk mesin ini.',
        ]);
    }

    /**
     * TASK-006 Slice C (koreksi B-3): resolusi identitas continuation authoritative.
     *
     * Occurrence memakai urutan yang sama dengan getExecutorContext()
     * (findScheduleDateOrNull), lalu execution canonical diverifikasi dengan
     * aturan identitas yang sama dengan findCanonicalExecution() +
     * assertStartableExecution(): tepat satu row per occurrence (termasuk
     * soft-deleted), non-deleted, machine_id cocok, dan status in_progress.
     * Gate ini fail-closed ke null, bukan melempar ValidationException;
     * penolakan final tetap menjadi wewenang jalur executor.
     */
    public function resolveContinuableExecution(Machine $machine): ?PmExecution
    {
        $scheduleDate = $this->findScheduleDateOrNull($machine, false, false);

        if (! $scheduleDate || $scheduleDate->status !== 'in_progress') {
            return null;
        }

        // Identitas canonical = occurrence, bukan machine; machine dipakai untuk
        // memvalidasi integritas parent (sama seperti findCanonicalExecution()).
        $executions = PmExecution::withTrashed()
            ->where('pm_schedule_date_id', $scheduleDate->id)
            ->orderBy('id')
            ->get();

        if ($executions->count() !== 1) {
            return null;
        }

        $execution = $executions->first();

        if ($execution->trashed()
            || (int) $execution->machine_id !== (int) $machine->id
            || $execution->status !== 'in_progress') {
            return null;
        }

        return $execution;
    }

    protected function findCanonicalExecution(Machine $machine, PmScheduleDate $scheduleDate, bool $lock = false): ?PmExecution
    {
        // Identity canonical hanya berdasarkan occurrence; machine dipakai untuk
        // memvalidasi integritas parent dan bukan sebagai uniqueness predicate.
        $query = PmExecution::withTrashed()
            ->where('pm_schedule_date_id', $scheduleDate->id)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $executions = $query->get();

        if ($executions->count() > 1) {
            throw ValidationException::withMessages([
                'machine' => 'Ditemukan execution PM duplikat untuk occurrence ini; proses dihentikan untuk menjaga histori.',
            ]);
        }

        $execution = $executions->first();
        if ($execution && (int) $execution->machine_id !== (int) $machine->id) {
            throw ValidationException::withMessages([
                'machine' => 'Execution PM memiliki relasi mesin yang tidak sesuai dengan occurrence; proses dihentikan untuk menjaga histori.',
            ]);
        }

        return $execution;
    }

    protected function assertStartableExecution(?PmExecution $execution): void
    {
        if (! $execution) {
            return;
        }

        if ($execution->trashed()) {
            throw ValidationException::withMessages([
                'machine' => 'Occurrence ini memiliki execution histori yang sudah dihapus; replacement tidak diizinkan.',
            ]);
        }

        if (in_array($execution->status, ['waiting_review', 'approved'], true)) {
            throw ValidationException::withMessages([
                'machine' => 'PM untuk occurrence ini sudah diproses dan tidak dapat dimulai ulang.',
            ]);
        }

        if ($execution->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'machine' => 'Status execution PM tidak dikenali; proses dihentikan.',
            ]);
        }
    }

    protected function ensureNextScheduleDateExists(PmScheduleDate $scheduleDate): void
    {
        // Keputusan lifecycle memakai state authoritative, bukan relasi yang
        // mungkin sudah ter-cache: Schedule dibaca fresh dari database, dan row
        // Machine sudah dikunci oleh startExecutionOnly() pada transaction yang sama.
        $schedule = $scheduleDate->schedule()->first();

        if (! $schedule) {
            return;
        }

        $machine = Machine::query()
            ->whereKey($scheduleDate->machine_id)
            ->first(['id', 'lifecycle_status', 'effective_live_from']);

        // Parent non-live tidak boleh menghasilkan occurrence baru, termasuk saat
        // execution lama tetap diselesaikan.
        if ($machine === null
            || ! $this->lifecyclePolicy->isLive('schedule', (string) $schedule->lifecycle_status)
            || ! $this->lifecyclePolicy->isLive('machine', (string) $machine->lifecycle_status)) {
            return;
        }

        $nextExistingScheduleDate = PmScheduleDate::query()
            ->where('pm_schedule_id', $scheduleDate->pm_schedule_id)
            ->where('machine_id', $scheduleDate->machine_id)
            ->whereDate('scheduled_date', '>', $scheduleDate->scheduled_date?->toDateString())
            ->orderBy('scheduled_date')
            ->first();

        if ($nextExistingScheduleDate) {
            return;
        }

        $nextScheduledDate = $this->resolveNextScheduledDate(
            $schedule,
            Carbon::parse((string) $scheduleDate->scheduled_date)->startOfDay(),
            $machine->effective_live_from,
        );

        if (! $nextScheduledDate) {
            return;
        }

        PmScheduleDate::query()->firstOrCreate(
            [
                'pm_schedule_id' => $scheduleDate->pm_schedule_id,
                'machine_id' => $scheduleDate->machine_id,
                'scheduled_date' => $nextScheduledDate->toDateString(),
            ],
            [
                'status' => 'scheduled',
                'status_changed_at' => null,
                'generated_at' => now(),
            ],
        );
    }

    protected function resolveNextScheduledDate(PmSchedule $schedule, Carbon $afterDate, ?Carbon $machineEffectiveLiveFrom = null): ?Carbon
    {
        $generateUntil = $schedule->generate_until?->copy()->startOfDay();

        if (! $generateUntil) {
            return null;
        }

        $cursor = $afterDate->copy()->addDay()->startOfDay();

        // BR-PM-LC-002: Floor lifecycle adalah batas paling akhir antara jendela
        // perencanaan ADR-009, start_date eksplisit, dan effective_live_from
        // Machine/Schedule, sehingga occurrence baru tidak pernah mendahului
        // boundary live terbaru. Nilai NULL legacy tetap fail-closed dan tidak
        // boleh diteruskan ke recurrence scan.
        $floor = $this->lifecyclePolicy->materializationFloor(
            $schedule->operational_from,
            $schedule->start_date,
            $machineEffectiveLiveFrom,
            $schedule->effective_live_from,
            BusinessDate::today(),
        );

        if ($floor === null) {
            return null;
        }

        if ($cursor->lt($floor)) {
            $cursor = $floor->copy();
        }

        while ($cursor->lte($generateUntil)) {
            $shouldInclude = false;

            if ($schedule->frequency_type === 'daily') {
                $shouldInclude = true;
            }

            if ($schedule->frequency_type === 'weekly') {
                $selectedDays = array_map('intval', $schedule->weekly_days ?? []);
                $shouldInclude = in_array((int) $cursor->dayOfWeek, $selectedDays, true);
            }

            if ($schedule->frequency_type === 'monthly') {
                $targetDay = max(1, min(31, (int) ($schedule->monthly_day ?? 1)));
                $validDay = min($targetDay, $cursor->copy()->endOfMonth()->day);
                $shouldInclude = $cursor->day === $validDay;
            }

            if ($shouldInclude) {
                return $cursor;
            }

            $cursor->addDay();
        }

        return null;
    }

    /**
     * Create or reuse exactly one canonical execution for machine/date/operator.
     * Lock order: Machine → PmScheduleDate → PmExecution.
     * Legacy duplicate rows block replacement fail-closed.
     *
     * @throws ValidationException
     */
    public function startExecutionOnly(
        Request $request,
        Machine $machine,
        User $operator,
    ): PmExecution {
        $expectedCollision = false;
        $collisionScheduleDateId = null;

        try {
            return DB::transaction(function () use ($request, $machine, $operator, &$expectedCollision, &$collisionScheduleDateId): PmExecution {
                // Parent machine dikunci lebih dahulu agar seluruh writer mengikuti urutan lock yang sama.
                $lockedMachine = Machine::query()
                    ->whereKey($machine->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $scheduleDate = $this->findScheduleDateForExecutor($lockedMachine, true, true);
                $collisionScheduleDateId = $scheduleDate->id;

                // Occurrence dan execution dibaca ulang setelah parent lock, bukan dari context request.
                $execution = $this->findCanonicalExecution($lockedMachine, $scheduleDate, true);
                if ($execution) {
                    $this->assertStartableExecution($execution);

                    return $execution;
                }

                try {
                    $execution = $this->createExecutionForStart($scheduleDate, $lockedMachine, $operator);
                } catch (UniqueConstraintViolationException $exception) {
                    // Hanya unique index canonical yang boleh masuk jalur pemulihan collision.
                    $expectedCollision = $this->isCanonicalScheduleDateCollision($exception);
                    throw $exception;
                }

                $scheduleDate->forceFill([
                    'status' => 'in_progress',
                    'status_changed_at' => now(),
                ])->save();

                $this->activityLogService->log(
                    request: $request,
                    moduleName: 'pm_executor',
                    action: 'start_pm',
                    description: sprintf('Mulai PM untuk mesin %s', $machine->machine_code),
                    tableName: 'pm_executions',
                    recordId: $execution->id,
                );

                return $execution;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $expectedCollision || ! $collisionScheduleDateId) {
                throw $exception;
            }

            return DB::transaction(function () use ($machine, $collisionScheduleDateId, $exception): PmExecution {
                // Setelah rollback, parent chain dikunci ulang untuk membaca row canonical authoritative.
                $lockedMachine = Machine::query()
                    ->whereKey($machine->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $scheduleDate = PmScheduleDate::query()
                    ->whereKey($collisionScheduleDateId)
                    ->where('machine_id', $lockedMachine->id)
                    ->with(['schedule.checksheetMachine.checksheet'])
                    ->lockForUpdate()
                    ->first();

                if (! $scheduleDate) {
                    throw $exception;
                }

                $execution = $this->findCanonicalExecution($lockedMachine, $scheduleDate, true);
                if (! $execution) {
                    throw $exception;
                }

                $this->assertStartableExecution($execution);

                if ($scheduleDate->status !== 'in_progress') {
                    $scheduleDate->forceFill([
                        'status' => 'in_progress',
                        'status_changed_at' => now(),
                    ])->save();
                }

                return $execution;
            });
        }
    }

    /**
     * BR-PM-EXEC-004: resolver aksi media — hanya mengembalikan execution canonical
     * yang sudah dimulai dan masih in_progress; tidak pernah membuat execution baru.
     * Urutan lock mengikuti writer lain: Machine → PmScheduleDate → PmExecution.
     *
     * @throws ValidationException
     */
    public function requireExistingInProgressExecution(Machine $machine): PmExecution
    {
        return DB::transaction(function () use ($machine): PmExecution {
            $lockedMachine = Machine::query()
                ->whereKey($machine->id)
                ->lockForUpdate()
                ->firstOrFail();

            $scheduleDate = $this->findScheduleDateForExecutor($lockedMachine, true, true);
            $execution = $this->findCanonicalExecution($lockedMachine, $scheduleDate, true);

            if (! $execution) {
                throw ValidationException::withMessages([
                    'machine' => self::MESSAGE_PM_NOT_STARTED,
                ]);
            }

            // Lifecycle execution menjadi keputusan akhir: waiting_review/approved,
            // soft-deleted, dan status tak dikenal ikut ditolak fail-closed.
            $this->assertStartableExecution($execution);

            return $execution;
        });
    }

    /**
     * ADR-007 Slice A: mengumpulkan snapshot identitas transaksi dari parent authoritative.
     * Lokasi wajib; checksheet wajib; assignment provenance wajib sama dengan machine terkunci.
     * Semua kegagalan bersifat fail-closed SEBELUM insert execution.
     *
     * @return array{machine_code_snapshot:string, machine_name_snapshot:string, location_code_snapshot:string, location_name_snapshot:string, checksheet_code_snapshot:string, checksheet_name_snapshot:string}
     *
     * @throws ValidationException
     */
    protected function resolveTransactionIdentitySnapshots(
        Machine $lockedMachine,
        PmScheduleDate $scheduleDate,
    ): array {
        // Lokasi authoritative dari machine terkunci; soft-delete/unresolvable ditolak.
        $location = $lockedMachine->location()->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'machine' => 'Lokasi mesin tidak tersedia; PM tidak dapat dimulai.',
            ]);
        }

        // Rantai provenance: ScheduleDate → Schedule → ChecksheetMachine → Checksheet.
        $scheduleDate->loadMissing('schedule.checksheetMachine.checksheet');
        $checksheetMachine = $scheduleDate->schedule?->checksheetMachine;
        $checksheet = $checksheetMachine?->checksheet;

        if (! $checksheetMachine || ! $checksheet) {
            throw ValidationException::withMessages([
                'machine' => 'Provenance checksheet PM tidak ditemukan; PM tidak dapat dimulai.',
            ]);
        }

        // Assignment provenance wajib menunjuk machine terkunci.
        if ((int) $checksheetMachine->machine_id !== (int) $lockedMachine->id) {
            throw ValidationException::withMessages([
                'machine' => 'Provenance checksheet tidak sesuai dengan mesin occurrence; PM tidak dapat dimulai.',
            ]);
        }

        // Snapshot diambil dari model authoritative — bukan request/controller/frontend.
        return [
            'machine_code_snapshot' => (string) $lockedMachine->machine_code,
            'machine_name_snapshot' => (string) $lockedMachine->machine_name,
            'location_code_snapshot' => (string) $location->location_code,
            'location_name_snapshot' => (string) $location->location_name,
            'checksheet_code_snapshot' => (string) $checksheet->checksheet_code,
            'checksheet_name_snapshot' => (string) $checksheet->checksheet_name,
        ];
    }

    /**
     * BR-PM-SCH-001: penanggalan future dari sudut bisnis (Asia/Jakarta).
     * Membandingkan tanggal kalender agar tetap benar walaupun timezone
     * aplikasi berbeda dengan timezone bisnis.
     */
    private function isFutureOccurrence(PmScheduleDate $scheduleDate): bool
    {
        return (string) $scheduleDate->scheduled_date?->toDateString() > BusinessDate::today()->toDateString();
    }

    /**
     * Memisahkan boundary insert agar klasifikasi collision dapat diuji tanpa
     * membuat migration unique index sebelum Slice 2 diotorisasi.
     * ADR-007 Slice A: snapshot identitas transaksi ikut di-insert pada creation yang sama.
     */
    protected function createExecutionForStart(
        PmScheduleDate $scheduleDate,
        Machine $machine,
        User $operator,
    ): PmExecution {
        // BR-PM-SCH-001: occurrence future tidak boleh membuat execution
        // canonical sebelum tanggal jadwal pada boundary creation authoritative.
        if ($this->isFutureOccurrence($scheduleDate)) {
            throw ValidationException::withMessages([
                'machine' => 'PM terjadwal belum dapat dimulai sebelum tanggal jadwalnya.',
            ]);
        }

        // TASK-006 Slice C (koreksi B-1): guard live-parent sebelum insert.
        // Sumber authoritative: $machine adalah instance terkunci dari
        // startExecutionOnly(); status Schedule dibaca fresh dari database.
        $scheduleStatus = (string) $scheduleDate->schedule()->value('lifecycle_status');

        if (! $this->lifecyclePolicy->isLive('machine', (string) $machine->lifecycle_status)) {
            throw ValidationException::withMessages([
                'machine' => 'Mesin tidak live; PM baru tidak dapat dimulai.',
            ]);
        }

        if (! $this->lifecyclePolicy->isLive('schedule', $scheduleStatus)) {
            throw ValidationException::withMessages([
                'machine' => 'Jadwal PM tidak live; PM baru tidak dapat dimulai.',
            ]);
        }

        $execution = PmExecution::query()->create([
            'pm_schedule_date_id' => $scheduleDate->id,
            'machine_id' => $machine->id,
            'operator_id' => $operator->id,
            'operator_name_snapshot' => $operator->name,
            'status' => 'in_progress',
            'started_at' => now(),
            ...$this->resolveTransactionIdentitySnapshots($machine, $scheduleDate),
        ]);

        // Definisi dibekukan pada transaksi creation yang sama; sesudah ini Executor
        // hanya membaca pm_execution_items (snapshot), bukan master checksheet.
        $this->captureDefinitionSnapshot($execution, $scheduleDate);

        return $execution;
    }

    /**
     * Bekukan definisi aktif saat Mulai PM sebagai baris-baris item tanpa hasil.
     * Definisi tetap hidup pada occurrence yang belum pernah dimulai karena
     * Start adalah satu-satunya jalur pembuatan execution canonical.
     *
     * @throws ValidationException
     */
    protected function captureDefinitionSnapshot(PmExecution $execution, PmScheduleDate $scheduleDate): void
    {
        $parts = $this->activeChecksheetParts($scheduleDate);
        $rows = $this->definitionRows($execution, $parts, true);

        // Mulai PM wajib menghasilkan checklist yang dapat dikerjakan. Marker
        // snapshot tidak boleh ditulis ketika konfigurasi aktif tidak memiliki
        // satu pun standard, agar transaksi Start sepenuhnya di-rollback.
        if ($rows === []) {
            throw ValidationException::withMessages([
                'machine' => 'Part atau standard checksheet belum tersedia.',
            ]);
        }

        PmExecutionItem::query()->insert($this->definitionRowsForInsert($rows));

        $execution->forceFill(['definition_snapshot_at' => now()])->save();
    }

    /**
     * Definisi aktual occurrence, terurut part lalu standard.
     * Pivot Master Action menang atas kolom legacy action_options.
     *
     * @return Collection<int, PmChecksheetPart>
     */
    private function activeChecksheetParts(PmScheduleDate $scheduleDate): Collection
    {
        $scheduleDate->loadMissing('schedule.checksheetMachine');

        return PmChecksheetPart::query()
            ->where('pm_checksheet_machine_id', $scheduleDate->schedule?->checksheetMachine?->id)
            ->where('is_active', true)
            ->with([
                'standards' => fn ($query) => $query->where('is_active', true)->with('actions')->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, PmChecksheetPart>  $parts
     * @return array<int, array<string, mixed>>
     */
    private function definitionRows(PmExecution $execution, Collection $parts, bool $forNewStart = false): array
    {
        $rows = [];
        $position = 0;

        foreach ($parts as $part) {
            foreach ($part->standards as $standard) {
                $actionOptions = $standard->input_type === 'action'
                    ? $this->definitionActionOptions($standard, $forNewStart)
                    : null;

                // Mapping pivot tetap dipertahankan untuk konfigurasi, tetapi
                // Start baru hanya boleh membekukan pilihan Master Action aktif.
                if ($forNewStart
                    && $standard->input_type === 'action'
                    && $standard->actions->isNotEmpty()
                    && $actionOptions === []
                ) {
                    throw ValidationException::withMessages([
                        'machine' => 'Standard action tidak memiliki opsi aktif.',
                    ]);
                }
                // Identitas pilihan multiple harus tunggal sebelum snapshot transaksi Start ditulis.
                if ($forNewStart
                    && $standard->effectiveSelectionMode() === 'multiple'
                    && ! $this->actionService->hasUniqueLabelIdentities($actionOptions ?? [])
                ) {
                    throw ValidationException::withMessages([
                        'machine' => 'Mode Pilih Banyak memerlukan pilihan Action dengan label unik.',
                    ]);
                }

                $position++;
                $rows[] = [
                    'pm_execution_id' => $execution->id,
                    'pm_checksheet_part_id' => $part->id,
                    'pm_checksheet_standard_id' => $standard->id,
                    'part_name_snapshot' => $part->part_name,
                    'standard_name_snapshot' => $standard->standard_name,
                    'input_type_snapshot' => $standard->input_type,
                    'selection_mode_snapshot' => $standard->effectiveSelectionMode(),
                    'action_options_snapshot' => $actionOptions,
                    'target_value_snapshot' => $standard->target_value,
                    'min_value_snapshot' => $standard->min_value,
                    'max_value_snapshot' => $standard->max_value,
                    'unit_snapshot' => $standard->unit,
                    'is_required_snapshot' => (bool) $standard->is_required,
                    'is_warning' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Insert massal melewati cast model, sehingga opsi action diubah menjadi
     * JSON string agar pembacaan cast 'array' tetap menghasilkan daftar opsi.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function definitionRowsForInsert(array $rows): array
    {
        return array_map(static function (array $row): array {
            if (isset($row['action_options_snapshot']) && is_array($row['action_options_snapshot'])) {
                $row['action_options_snapshot'] = json_encode($row['action_options_snapshot'], JSON_THROW_ON_ERROR);
            }

            return $row;
        }, $rows);
    }

    /**
     * @return array<int, string>
     */
    private function definitionActionOptions(PmChecksheetStandard $standard, bool $onlyActive = false): array
    {
        if ($standard->actions->isNotEmpty()) {
            return $standard->actions
                ->when($onlyActive, fn (Collection $actions) => $actions->where('is_active', true))
                ->pluck('action_label')
                ->filter(fn ($label): bool => filled($label))
                ->map(fn ($label): string => strtoupper((string) $label))
                ->values()
                ->all();
        }

        return collect($standard->action_options ?? [])
            ->map(fn ($option): string => strtoupper((string) $option))
            ->values()
            ->all();
    }

    /**
     * Item legacy yang sudah memiliki definisi snapshot lengkap adalah bukti
     * historis. Capture hanya memvalidasinya lalu menulis marker; definisi
     * master saat ini hanya dipakai untuk recovery execution tanpa item.
     *
     * @return array{applied: bool, skipped: bool, reason: string|null, inserted: int, updated: int, conflicts: array<int, string>}
     */
    public function captureLegacyDefinitions(int $executionId, bool $apply): array
    {
        return DB::transaction(function () use ($executionId, $apply): array {
            $report = [
                'applied' => false,
                'skipped' => false,
                'reason' => null,
                'inserted' => 0,
                'updated' => 0,
                'conflicts' => [],
            ];

            // Pre-read tanpa kunci hanya untuk menemukan ID parent; kunci
            // tetap diambil sesuai urutan wajib pada lock di bawah.
            $discovery = PmExecution::withTrashed()->find($executionId);
            if (! $discovery) {
                $report['conflicts'][] = "Execution {$executionId} tidak ditemukan.";

                return $report;
            }

            // Urutan kunci wajib: Machine -> PmScheduleDate -> PmExecution -> item.
            $machine = Machine::query()->whereKey($discovery->machine_id)->lockForUpdate()->first();
            if (! $machine) {
                $report['conflicts'][] = "Machine parent execution {$executionId} tidak ditemukan.";

                return $report;
            }

            $scheduleDate = PmScheduleDate::query()
                ->whereKey($discovery->pm_schedule_date_id)
                ->lockForUpdate()
                ->first();
            if (! $scheduleDate || (int) $scheduleDate->machine_id !== (int) $machine->id) {
                $report['conflicts'][] = "Occurrence parent execution {$executionId} tidak ditemukan atau tidak sesuai mesin.";

                return $report;
            }

            $execution = PmExecution::withTrashed()->whereKey($executionId)->lockForUpdate()->first();
            if (! $execution) {
                $report['conflicts'][] = "Execution {$executionId} tidak ditemukan setelah penguncian.";

                return $report;
            }

            if ($execution->trashed()) {
                $report['conflicts'][] = "Execution {$executionId} sudah di-soft-delete; capture dihentikan.";

                return $report;
            }

            if ($execution->definition_snapshot_at !== null) {
                $report['skipped'] = true;
                $report['reason'] = 'definition_snapshot_at sudah terisi';

                return $report;
            }

            if ($execution->status !== 'in_progress') {
                $report['skipped'] = true;
                $report['reason'] = "status {$execution->status} di luar jalur Executor";

                return $report;
            }

            $items = PmExecutionItem::query()
                ->where('pm_execution_id', $execution->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isNotEmpty()) {
                foreach ($items as $item) {
                    $this->validateLegacySnapshotItem($item, $report['conflicts']);
                }

                if ($report['conflicts'] !== []) {
                    return $report;
                }

                if (! $apply) {
                    // Pratinjau item existing hanya melaporkan marker; tidak ada
                    // definisi historis yang akan diubah atau ditulis ulang.
                    return $report;
                }

                $execution->forceFill(['definition_snapshot_at' => now()])->save();
                $report['applied'] = true;

                return $report;
            }

            // Recovery cutover untuk execution tanpa bukti item historis: hanya
            // pada jalur ini definisi master aktif saat ini boleh dibekukan.
            $definitionRows = $this->definitionRows($execution, $this->activeChecksheetParts($scheduleDate));
            if ($definitionRows === []) {
                $report['conflicts'][] = "Execution {$execution->id} tidak memiliki item maupun definisi master aktif.";

                return $report;
            }

            if (! $apply) {
                // Pratinjau: item recovery belum ditulis sampai --apply eksplisit.
                $report['inserted'] = count($definitionRows);

                return $report;
            }

            $now = now();
            foreach ($definitionRows as &$definitionRow) {
                $definitionRow['created_at'] = $now;
                $definitionRow['updated_at'] = $now;
            }
            unset($definitionRow);

            PmExecutionItem::query()->insert($this->definitionRowsForInsert($definitionRows));
            $execution->forceFill(['definition_snapshot_at' => $now])->save();
            $report['inserted'] = count($definitionRows);
            $report['applied'] = true;

            return $report;

        });
    }

    /**
     * @param  array<int, string>  $conflicts
     */
    private function validateLegacySnapshotItem(PmExecutionItem $item, array &$conflicts): void
    {
        if (! filled($item->part_name_snapshot)
            || ! filled($item->standard_name_snapshot)
            || ! in_array($item->input_type_snapshot, ['action', 'number', 'range'], true)
        ) {
            $conflicts[] = "Item {$item->id} tidak memiliki definisi snapshot yang lengkap.";

            return;
        }

        if ($item->input_type_snapshot !== 'action') {
            return;
        }

        $actionOptions = $this->normalizedActionOptions($item->action_options_snapshot);
        if ($actionOptions === []) {
            $conflicts[] = "Item action {$item->id} tidak memiliki pilihan snapshot yang valid.";

            return;
        }

        $value = $item->action_value !== null ? strtoupper(trim((string) $item->action_value)) : '';
        if ($value !== '' && ! in_array($value, $actionOptions, true)) {
            $conflicts[] = "Value action '{$value}' pada item {$item->id} tidak ada di pilihan snapshot.";
        }
    }

    /**
     * Memastikan hanya named index canonical yang diperlakukan sebagai retry.
     */
    protected function isCanonicalScheduleDateCollision(UniqueConstraintViolationException $exception): bool
    {
        $canonicalIndex = 'pm_executions_pm_schedule_date_id_unique';
        $errorInfo = $exception->errorInfo ?? [];
        $evidence = implode(' ', array_map(static fn ($value): string => (string) $value, [
            $exception->getMessage(),
            $exception->getPrevious()?->getMessage(),
            ...$errorInfo,
        ]));

        return str_contains($evidence, $canonicalIndex)
            && preg_match('/^insert\s+into\s+[`"]?pm_executions[`"]?/i', trim($exception->getSql())) === 1;
    }
}
