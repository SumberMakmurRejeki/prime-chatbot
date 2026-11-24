<?php

namespace App\Services\Master;

use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmSchedule;
use App\Services\PM\BusinessDate;
use App\Services\PM\LifecyclePolicy;
use App\Services\PM\PlanningPeriodPolicy;
use App\Services\PM\PmScheduleDateGenerator;
use App\Services\PM\PmScheduleDateReconciler;
use App\Services\PM\StalePreviewException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Layanan preview dan apply untuk update jadwal PM checksheet.
 *
 * Preview: membaca state saat ini, menghitung desired dates dari payload,
 * mengklasifikasi perbedaan, menghasilkan fingerprint, tanpa menulis DB.
 *
 * Apply: lock baris, verifikasi fingerprint cocok, persist config baru,
 * reconcile dates, kembalikan token baru.
 */
class PmScheduleUpdateService
{
    public function __construct(
        protected PmScheduleDateReconciler $reconciler,
        protected PlanningPeriodPolicy $planningPeriodPolicy,
        protected LifecyclePolicy $lifecyclePolicy,
    ) {}

    /**
     * Preview dampak perubahan jadwal tanpa menulis ke database.
     *
     * @param  array<string, mixed>  $schedulePayload  Payload schedule dari wizard.
     * @return array{status: string, impact: array<string, list<string>>, counts: array<string, int>, unresolved: bool, has_conflicts: bool, has_changes: bool, token: string, dormant_assignments: list<array<string, mixed>>, business_today: string}
     */
    public function preview(PmChecksheet $checksheet, array $schedulePayload, ?array $candidateMachineIds = null): array
    {
        $candidateMachineIds = $this->normalizeCandidateMachineIds($candidateMachineIds);
        // Hitung tanggal bisnis hari ini (Asia/Jakarta).
        $businessToday = BusinessDate::today();

        // Invariant era current harus utuh sebelum preview membaca state apa pun.
        $this->assertSingleCurrentEra($checksheet);

        // Payload atau schedule tersimpan NULL sama-sama unresolved.
        // Current era saja menjadi sumber state; dormant assignment tidak dianggap unresolved.
        $hasUnresolvedSchedule = PmSchedule::query()
            ->currentEra()
            ->whereHas('checksheetMachine', fn ($query) => $query->where('pm_checksheet_id', $checksheet->id))
            ->whereNull('operational_from')
            ->exists();

        if (empty($schedulePayload['operational_from'] ?? null) || $hasUnresolvedSchedule) {
            return [
                'status' => 'unresolved',
                'unresolved' => true,
                'has_changes' => false,
                'has_conflicts' => false,
                'impact' => [
                    'created' => [],
                    'removed' => [],
                    'retained' => [],
                    'protected' => [],
                    'conflict' => [],
                ],
                'counts' => [
                    'created' => 0,
                    'removed' => 0,
                    'retained' => 0,
                    'protected' => 0,
                    'conflict' => 0,
                ],
                'token' => '',
                'dormant_assignments' => [],
                // Kontrak response preview selalu menyertakan business_today,
                // termasuk jalur unresolved, agar bentuk response konsisten.
                'business_today' => $businessToday->toDateString(),
            ];

        }

        // Hitung jendela operasional: start_date dan generate_until.
        $window = $this->resolveWindow($schedulePayload, $businessToday);

        // Floor dihitung per assignment karena BR-PM-LC-002 memasukkan
        // effective_live_from Machine/Schedule sebagai lower bound tambahan;
        // satu floor payload-wide tidak dapat mewakilinya.
        $payloadOperationalFrom = Carbon::parse($schedulePayload['operational_from'])->startOfDay();
        $payloadStartDate = $window['start_date'] !== null ? Carbon::parse($window['start_date']) : null;

        // Historical ENDED era tidak ikut preview; dates-nya adalah bukti histori,
        // bukan konfigurasi current yang boleh dimutasi atau memengaruhi impact.
        $assignments = PmChecksheetMachine::query()
            ->where('pm_checksheet_id', $checksheet->id)
            ->with([
                'machine',
                'schedules' => function ($query): void {
                    $query->currentEra()->with([
                        'scheduleDates' => function ($dateQuery): void {
                            $dateQuery->withTrashed()->orderBy('scheduled_date')->with([
                                'executions' => fn ($q) => $q->withTrashed()->orderBy('id'),
                            ]);
                        },
                    ]);
                },
            ])
            ->orderBy('machine_id')
            ->get();
        $candidateMachines = $candidateMachineIds === null
            ? collect()
            : Machine::query()
                ->whereIn('id', $candidateMachineIds)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

        // Klasifikasi setiap baris tanggal yang ada di database (current era saja).
        $created = [];
        $removed = [];
        $retained = [];
        $protected = [];
        $conflict = [];

        // Assignment dengan hanya era ENDED dilaporkan dormant; ini tidak
        // membatalkan update assignment sibling pada checksheet yang sama.
        $dormantAssignments = [];

        foreach ($assignments as $assignment) {
            // Assignment tanpa current era TIDAK boleh memakai era ENDED pertama
            // sebagai konfigurasi current yang bisa dimutasi.
            if ($assignment->schedules->isEmpty()) {
                $hasEndedHistory = PmSchedule::query()
                    ->withTrashed()
                    ->where('pm_checksheet_machine_id', $assignment->id)
                    ->where('lifecycle_status', 'ended')
                    ->exists();
                if ($hasEndedHistory) {
                    $dormantAssignments[] = [
                        'assignment_id' => $assignment->id,
                        'status' => 'dormant_skipped',
                        'reason' => 'Assignment hanya memiliki era Schedule berstatus ended.',
                        'instruction' => 'Era operasional baru hanya dapat dibuat melalui recommission yang eksplisit.',
                    ];
                }

                continue;
            }

            // Apply hanya merekonsiliasi assignment dengan Machine live; preview
            // harus melaporkan impact yang sama (BR-PM-LC-001).
            if (! $this->lifecyclePolicy->isLive('machine', (string) $assignment->machine?->lifecycle_status)) {
                continue;
            }

            // Preview harus mencerminkan mutasi yang benar-benar dilakukan apply:
            // era PAUSED tidak direkonsiliasi, sehingga tidak dihitung sebagai impact.
            $activeSchedules = $assignment->schedules
                ->filter(fn (PmSchedule $candidate): bool => $candidate->lifecycle_status === 'active');

            foreach ($activeSchedules as $schedule) {
                $floor = $this->lifecyclePolicy->materializationFloor(
                    $payloadOperationalFrom,
                    $payloadStartDate,
                    $assignment->machine?->effective_live_from,
                    $schedule->effective_live_from,
                    $businessToday,
                );

                if ($floor === null) {
                    continue;
                }

                // Tanggal desired payload dihitung dari floor schedule ini agar
                // sama dengan hasil reconciler saat apply.
                $desiredDates = PmScheduleDateGenerator::generate([
                    'frequency_type' => $schedulePayload['frequency_type'],
                    'weekly_days' => $schedulePayload['weekly_days'] ?? [],
                    'monthly_day' => $schedulePayload['monthly_day'] ?? null,
                    'start_date' => $floor->toDateString(),
                    'generate_until' => $window['generate_until'],
                ]);
                $desiredSet = array_fill_keys($desiredDates, true);
                $scheduleDateMap = [];

                foreach ($schedule->scheduleDates as $dateRow) {
                    $dateStr = $dateRow->scheduled_date->toDateString();
                    $scheduleDateMap[$dateStr] = true;

                    // Baris sebelum floor dipertahankan apa adanya.
                    if ($dateRow->scheduled_date->lt($floor)) {
                        continue;
                    }

                    $isDesired = isset($desiredSet[$dateStr]);
                    $isMutable = in_array($dateRow->status, PmScheduleDateReconciler::MUTABLE_STATUSES, true)
                        && $dateRow->executions->isEmpty();

                    if ($isDesired) {
                        if ($dateRow->trashed()) {
                            if ($isMutable) {
                                $created[] = $dateStr;
                            } else {
                                $conflict[] = $dateStr;
                            }
                        } else {
                            $retained[] = $dateStr;
                        }
                    } elseif ($isMutable) {
                        $removed[] = $dateStr;
                    } elseif (! $dateRow->trashed()) {
                        $protected[] = $dateStr;
                    }
                }

                // Tanggal desired yang belum ada dievaluasi per schedule aktif.
                foreach ($desiredDates as $dateStr) {
                    if (! isset($scheduleDateMap[$dateStr])) {
                        $created[] = $dateStr;
                    }
                }
            }
        }

        $candidateAssignments = [];
        if ($candidateMachineIds !== null) {
            $assignedMachineIds = $assignments->pluck('machine_id')
                ->map(fn ($machineId): int => (int) $machineId)
                ->all();

            foreach (array_diff($candidateMachineIds, $assignedMachineIds) as $machineId) {
                $machine = $candidateMachines->get($machineId);
                if ($machine === null
                    || ! $machine->is_active
                    || ! $this->lifecyclePolicy->isLive('machine', (string) $machine->lifecycle_status)
                ) {
                    throw ValidationException::withMessages([
                        'wizard_payload.selected_machine_ids' => 'Mesin baru harus aktif untuk menerapkan jadwal.',
                    ]);
                }

                $candidateAssignments[] = [
                    'machine_id' => (int) $machine->id,
                    'machine_code' => $machine->machine_code,
                    'machine_name' => $machine->machine_name,
                ];

                $floor = $this->lifecyclePolicy->materializationFloor(
                    $payloadOperationalFrom,
                    $payloadStartDate,
                    $machine->effective_live_from,
                    null,
                    $businessToday,
                );
                if ($floor !== null) {
                    $created = [
                        ...$created,
                        ...PmScheduleDateGenerator::generate([
                            'frequency_type' => $schedulePayload['frequency_type'],
                            'weekly_days' => $schedulePayload['weekly_days'] ?? [],
                            'monthly_day' => $schedulePayload['monthly_day'] ?? null,
                            'start_date' => $floor->toDateString(),
                            'generate_until' => $window['generate_until'],
                        ]),
                    ];
                }
            }
        }
        sort($created);
        sort($removed);
        sort($retained);
        sort($protected);
        sort($conflict);

        $hasConflicts = count($conflict) > 0;
        $hasChanges = count($created) + count($removed) > 0 || $candidateAssignments !== [];

        // Tentukan status: conflict > normal > zero.
        if ($hasConflicts) {
            $status = 'conflict';
        } elseif ($hasChanges) {
            $status = 'normal';
        } else {
            $status = 'zero';
        }

        $result = [
            'status' => $status,
            'impact' => [
                'created' => $created,
                'removed' => $removed,
                'retained' => $retained,
                'protected' => $protected,
                'conflict' => $conflict,
            ],
            'counts' => [
                'created' => count($created),
                'removed' => count($removed),
                'retained' => count($retained),
                'protected' => count($protected),
                'conflict' => count($conflict),
            ],
            'unresolved' => $hasConflicts,
            'has_conflicts' => $hasConflicts,
            'has_changes' => $hasChanges,
            'token' => $this->fingerprint($checksheet, $businessToday, $schedulePayload, $candidateMachineIds, $candidateMachines),
            'dormant_assignments' => $dormantAssignments,
            'business_today' => $businessToday->toDateString(),
        ];
        if ($candidateMachineIds !== null) {
            $result['candidate_assignments'] = $candidateAssignments;
        }

        return $result;
    }

    /**
     * Hitung fingerprint deterministik atas state tersimpan dan payload preview.
     */
    public function fingerprint(
        PmChecksheet $checksheet,
        Carbon $businessToday,
        array $schedulePayload = [],
        ?array $candidateMachineIds = null,
        ?Collection $machineSnapshots = null,
    ): string {
        $candidateMachineIds = $this->normalizeCandidateMachineIds($candidateMachineIds);
        $data = [
            'business_today' => $businessToday->toDateString(),
            'checksheet_id' => $checksheet->id,
            'schedule_payload' => $schedulePayload,
            'machines' => [],
        ];
        if ($candidateMachineIds !== null) {
            $candidateMachines = $machineSnapshots ?? Machine::query()
                ->whereIn('id', $candidateMachineIds)
                ->orderBy('id')
                ->get()
                ->keyBy('id');
            $data['candidate_machine_ids'] = $candidateMachineIds;
            $data['candidate_machines'] = [];

            foreach ($candidateMachineIds as $machineId) {
                $machine = $candidateMachines->get($machineId);
                $data['candidate_machines'][] = [
                    'machine_id' => $machineId,
                    'exists' => $machine !== null,
                    'is_active' => $machine?->is_active,
                    'lifecycle_status' => $machine?->lifecycle_status,
                    'effective_live_from' => $machine?->effective_live_from?->toDateString(),
                ];
            }
        }

        $assignments = PmChecksheetMachine::query()
            ->where('pm_checksheet_id', $checksheet->id)
            ->with([
                'machine',
                // Fingerprint hanya membaca era current; histori ENDED bukan
                // bagian konfigurasi yang dimutasi apply.
                'schedules' => function ($query): void {
                    $query->currentEra()->with([
                        'scheduleDates' => function ($dateQuery): void {
                            $dateQuery->withTrashed()->orderBy('scheduled_date')->with([
                                'executions' => fn ($q) => $q->withTrashed()->orderBy('id'),
                            ]);
                        },
                    ]);
                },
            ])
            ->orderBy('machine_id')
            ->get();

        foreach ($assignments as $assignment) {
            $machine = $machineSnapshots?->get((int) $assignment->machine_id) ?? $assignment->machine;
            $machineData = [
                'machine_id' => $assignment->machine_id,
                'lifecycle_status' => $machine?->lifecycle_status,
                'effective_live_from' => $machine?->effective_live_from?->toDateString(),
                'schedules' => [],
            ];

            foreach ($assignment->schedules as $schedule) {
                $scheduleData = [
                    'id' => $schedule->id,
                    'machine_id' => $assignment->machine_id,
                    'lifecycle_status' => $schedule->lifecycle_status,
                    'effective_live_from' => $schedule->effective_live_from?->toDateString(),
                    'frequency_type' => $schedule->frequency_type,
                    'weekly_days' => $schedule->weekly_days,
                    'monthly_day' => $schedule->monthly_day,
                    'operational_from' => $schedule->operational_from?->toDateString(),
                    'start_date' => $schedule->start_date?->toDateString(),
                    'generate_until' => $schedule->generate_until?->toDateString(),
                    'is_active' => $schedule->is_active,
                    'dates' => [],
                ];

                foreach ($schedule->scheduleDates as $dateRow) {
                    // Bangun data tanggal beserta execution-nya untuk fingerprint.
                    $dateData = [
                        'id' => $dateRow->id,
                        'scheduled_date' => $dateRow->scheduled_date->toDateString(),
                        'status' => $dateRow->status,
                        'deleted_at' => $dateRow->deleted_at?->toDateTimeString(),
                        'executions' => [],
                    ];

                    foreach ($dateRow->executions as $execution) {
                        $dateData['executions'][] = [
                            'id' => $execution->id,
                            'status' => $execution->status,
                            'deleted_at' => $execution->deleted_at?->toDateTimeString(),
                        ];
                    }

                    // Append dateData ke scheduleData setelah semua execution diproses.
                    $scheduleData['dates'][] = $dateData;
                }

                // Append scheduleData ke machineData setelah semua dates diproses.
                $machineData['schedules'][] = $scheduleData;
            }

            // Append machineData ke data setelah semua schedules diproses.
            $data['machines'][] = $machineData;
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $json, config('app.key'));
    }

    /**
     * @param  array<int, int|string>|null  $machineIds
     * @return list<int>|null
     */
    private function normalizeCandidateMachineIds(?array $machineIds): ?array
    {
        if ($machineIds === null) {
            return null;
        }

        $normalizedIds = array_values(array_unique(array_map(
            static fn (int|string $machineId): int => (int) $machineId,
            $machineIds,
        )));
        sort($normalizedIds, SORT_NUMERIC);

        return $normalizedIds;
    }

    /**
     * Pastikan paling banyak satu era current per assignment pada checksheet.
     *
     * Dua era non-terminal untuk assignment yang sama melanggar invariant
     * TASK-006/ADR-010. Fail closed (bukan memilih era secara implisit) agar
     * tidak ada mutasi yang dilakukan pada state yang ambigu.
     *
     * @throws ValidationException Ketika ditemukan era current ganda.
     */
    private function assertSingleCurrentEra(PmChecksheet $checksheet): void
    {
        // Duplikat era diperiksa per assignment; soft-deleted tidak dihitung.
        $duplicates = PmSchedule::query()
            ->currentEra()
            ->whereHas(
                'checksheetMachine',
                fn ($query) => $query->where('pm_checksheet_id', $checksheet->id)
            )
            ->select('pm_checksheet_machine_id')
            ->selectRaw('COUNT(*) AS era_count')
            ->groupBy('pm_checksheet_machine_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('pm_checksheet_machine_id');

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([
                'schedule' => 'Integritas era Schedule ganda terdeteksi; update dibatalkan tanpa mutasi apapun.',
            ]);
        }
    }

    /**
     * Terapkan perubahan jadwal dengan proteksi transaksi dan locking.
     *
     * Lock pm_checksheets + pm_schedules rows BEFORE re-evaluating fingerprint.
     * Ini mencegah apply membaca state yang berubah antara preview dan apply
     * oleh proses konkuren. Pm_schedule_dates di-lock oleh reconciler saat
     * reconcile().
     *
     * @param  array<string, mixed>  $schedulePayload  Payload schedule dari wizard.
     * @return array{status: string, counts: array<string, int>, has_conflicts: bool, has_changes: bool, token: string}
     *
     * @throws StalePreviewException Jika fingerprint tidak cocok (state berubah).
     */
    public function apply(
        PmChecksheet $checksheet,
        array $schedulePayload,
        string $token,
        ?array $candidateMachineIds = null,
    ): array {
        $candidateMachineIds = $this->normalizeCandidateMachineIds($candidateMachineIds);
        // Tolak apply unresolved sebelum lock, token, transaksi, atau mutasi apa pun.
        if (empty($schedulePayload['operational_from'] ?? null)) {
            return [
                'status' => 'unresolved',
                'message' => 'Tanggal awal operasional wajib diisi sebelum menerapkan jadwal.',
            ];
        }

        return DB::transaction(function () use ($checksheet, $schedulePayload, $token, $candidateMachineIds): array {
            // Tangkap tanggal bisnis di dalam transaksi agar konsisten dengan lock.
            $businessToday = BusinessDate::today();
            // Lock baris checksheet untuk mencegah perubahan konkuren.
            $lockedChecksheet = PmChecksheet::query()
                ->lockForUpdate()
                ->findOrFail($checksheet->id);

            // TASK-003 Slice 3: lock every persisted assignment Machine plus any
            // newly selected candidate Machine before locking schedules.
            $existingMachineIds = PmChecksheetMachine::query()
                ->where('pm_checksheet_id', $lockedChecksheet->id)
                ->orderBy('machine_id', 'asc')
                ->pluck('machine_id')
                ->map(fn ($machineId): int => (int) $machineId)
                ->all();
            $machineIds = array_values(array_unique([
                ...$existingMachineIds,
                ...($candidateMachineIds ?? []),
            ]));
            sort($machineIds, SORT_NUMERIC);
            $machineSnapshots = collect();
            if ($machineIds !== []) {
                $machineSnapshots = Machine::query()
                    ->whereIn('id', $machineIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
            }

            // TASK-006 Slice C: lock hanya era current (active/paused); era ENDED
            // tidak boleh menjadi target mutasi maupun sumber keputusan apply.
            $lockedSchedules = PmSchedule::query()
                ->currentEra()
                ->whereHas(
                    'checksheetMachine',
                    fn ($query) => $query->where('pm_checksheet_id', $lockedChecksheet->id)
                )
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // Schedule legacy tetap unresolved meskipun payload membawa tanggal valid.
            if ($lockedSchedules->contains(fn (PmSchedule $schedule): bool => $schedule->operational_from === null)) {
                return [
                    'status' => 'unresolved',
                    'message' => 'Jadwal tersimpan tanpa tanggal awal operasional belum dapat diterapkan.',
                ];
            }

            $currentFingerprint = $this->fingerprint(
                $lockedChecksheet,
                $businessToday,
                $schedulePayload,
                $candidateMachineIds,
                $machineSnapshots,
            );

            // Verifikasi token cocok. Jika tidak, state berubah antara preview
            // dan apply -> lempar StalePreviewException.
            if (! hash_equals($token, $currentFingerprint)) {
                throw new StalePreviewException;
            }

            // Invariant satu era current per assignment diverifikasi lagi
            // di dalam transaksi sebelum mutasi apa pun dilakukan.
            $this->assertSingleCurrentEra($lockedChecksheet);

            // Persist konfigurasi schedule baru; assignment dormant dilaporkan
            // sebagai hasil terstruktur, bukan kegagalan seluruh apply.
            $dormantAssignments = $this->persistScheduleConfig($lockedChecksheet, $schedulePayload, $businessToday);

            // Laravel meneruskan Relations\HasMany untuk eager-load bertingkat,
            // bukan Builder, sehingga closure tidak diberi type-hint Builder.
            $schedules = PmSchedule::query()
                ->currentEra()
                ->whereHas(
                    'checksheetMachine',
                    fn ($query) => $query->where('pm_checksheet_id', $lockedChecksheet->id)
                )
                ->orderBy('id', 'asc')
                ->get();

            // Reconcile setiap schedule: lock dates, bandingkan dengan desired,
            // terapkan mutasi (create/restore/remove).
            $totalCounts = [
                'examined' => 0,
                'created' => 0,
                'restored' => 0,
                'removed' => 0,
                'unchanged' => 0,
                'conflicts' => 0,
            ];

            foreach ($schedules as $schedule) {
                // PAUSED dipertahankan apa adanya: edit konfigurasi tidak boleh
                // memicu materialisasi atau resume implisit.
                if ($schedule->lifecycle_status !== 'active') {
                    continue;
                }

                $result = $this->reconciler->reconcile($schedule);

                foreach ($totalCounts as $key => $value) {
                    $totalCounts[$key] = $value + $result[$key];
                }
            }

            // Hitung ulang fingerprint baru setelah mutasi.
            $freshToken = $this->fingerprint(
                $lockedChecksheet->fresh(),
                $businessToday,
                $schedulePayload,
                $candidateMachineIds,
                $machineSnapshots,
            );

            return [
                'status' => 'applied',
                'counts' => $totalCounts,
                'has_conflicts' => $totalCounts['conflicts'] > 0,
                'has_changes' => $totalCounts['created'] + $totalCounts['restored'] + $totalCounts['removed'] > 0,
                'token' => $freshToken,
                'dormant_assignments' => $dormantAssignments,
            ];
        });
    }

    /**
     * Hitung jendela operasional dari payload schedule.
     *
     * Logika sama dengan PmChecksheetService::resolveScheduleWindow():
     * - operational_from ada -> start_date = operational_from,
     *   generate_until = planning_start + initialMonths.
     * - operational_from kosong -> fallback ke start_date/generate_until
     *   dari payload (jalur legacy).
     *
     * @param  array<string, mixed>  $schedulePayload
     * @return array{start_date: string|null, generate_until: string|null}
     */
    private function resolveWindow(array $schedulePayload, Carbon $businessToday): array
    {
        $operationalFrom = $schedulePayload['operational_from'] ?? null;

        if (! empty($operationalFrom)) {
            $operationalFromDate = Carbon::parse($operationalFrom)->startOfDay();
            $window = $this->planningPeriodPolicy->planningWindow($businessToday, $operationalFromDate);

            return [
                'start_date' => $operationalFromDate->toDateString(),
                'generate_until' => $window['planning_end']->toDateString(),
            ];
        }

        // Jalur legacy: gunakan start_date/generate_until dari payload.
        return [
            'start_date' => $schedulePayload['start_date'] ?? null,
            'generate_until' => $schedulePayload['generate_until'] ?? null,
        ];
    }

    /**
     * Persist konfigurasi schedule ke database (upsert assignment + schedule).
     *
     * Era current di-resolve eksplisit; assignment dengan hanya era ENDED
     * dikembalikan sebagai hasil dormant (bukan exception) agar assignment
     * sibling tetap dapat diproses.
     *
     * @param  array<string, mixed>  $schedulePayload
     * @return list<array{assignment_id: int, status: string, reason: string, instruction: string}>
     */
    private function persistScheduleConfig(PmChecksheet $checksheet, array $schedulePayload, Carbon $businessToday): array
    {
        $selectedMachineIds = array_map('intval', $checksheet->machineAssignments()->pluck('machine_id')->all());
        $window = $this->resolveWindow($schedulePayload, $businessToday);
        $dormantAssignments = [];

        foreach ($selectedMachineIds as $machineId) {
            $assignment = PmChecksheetMachine::query()->firstOrCreate(
                [
                    'pm_checksheet_id' => $checksheet->id,
                    'machine_id' => $machineId,
                ],
                ['assigned_at' => now()],
            );

            // Ambiguity tidak boleh diselesaikan dengan first(); current era
            // harus eksplisit dan historical ENDED tidak boleh dimutasi.
            $currentSchedules = PmSchedule::query()
                ->where('pm_checksheet_machine_id', $assignment->id)
                ->currentEra()
                ->orderBy('id')
                ->get();

            if ($currentSchedules->count() > 1) {
                throw ValidationException::withMessages([
                    'schedule' => 'Integritas era Schedule ganda terdeteksi; update dibatalkan.',
                ]);
            }

            $existingSchedule = $currentSchedules->first();
            if ($existingSchedule === null) {
                $hasEndedHistory = PmSchedule::query()
                    ->withTrashed()
                    ->where('pm_checksheet_machine_id', $assignment->id)
                    ->where('lifecycle_status', 'ended')
                    ->exists();

                if ($hasEndedHistory) {
                    $dormantAssignments[] = [
                        'assignment_id' => $assignment->id,
                        'status' => 'dormant_skipped',
                        'reason' => 'Assignment hanya memiliki era Schedule berstatus ended.',
                        'instruction' => 'Era operasional baru hanya dapat dibuat melalui recommission yang eksplisit.',
                    ];

                    continue;
                }
            }

            $attributes = [
                'frequency_type' => $schedulePayload['frequency_type'],
                'weekly_days' => $schedulePayload['frequency_type'] === 'weekly'
                    ? array_values($schedulePayload['weekly_days'] ?? [])
                    : null,
                'monthly_day' => $schedulePayload['frequency_type'] === 'monthly'
                    ? $schedulePayload['monthly_day']
                    : null,
                'operational_from' => $window['start_date'],
                'start_date' => $window['start_date'],
                'generate_until' => $window['generate_until'],
            ];

            if ($existingSchedule === null || $existingSchedule->lifecycle_status === 'active') {
                $attributes = [...$attributes, ...$this->lifecyclePolicy->scheduleProjection(true)];
            }

            // Mutasi konfigurasi saja: rekonsiliasi tanggal dimiliki oleh
            // apply() sebagai satu-satunya pemilik hasil otoritatif, sehingga
            // setiap schedule tidak pernah direkonsiliasi dua kali.
            if ($existingSchedule === null) {
                PmSchedule::query()->create([
                    'pm_checksheet_machine_id' => $assignment->id,
                    ...$attributes,
                ]);
            } else {
                $existingSchedule->fill($attributes)->save();
            }
        }

        return $dormantAssignments;
    }
}
