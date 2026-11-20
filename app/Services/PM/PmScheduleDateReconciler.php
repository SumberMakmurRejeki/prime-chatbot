<?php

namespace App\Services\PM;

use App\Models\Machine;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PmScheduleDateReconciler
{
    // Status occurrence yang boleh direconcile. Sumber tunggal untuk
    // klasifikasi protected/mutable pada reconciliation dan preview.
    public const MUTABLE_STATUSES = ['scheduled', 'overdue', 'missed'];

    public function __construct(
        private readonly LifecyclePolicy $lifecyclePolicy,
    ) {}

    /**
     * @return array{examined: int, created: int, restored: int, removed: int, unchanged: int, conflicts: int, unresolved?: bool}
     */
    public function reconcile(PmSchedule $schedule): array
    {
        // Jadwal tanpa awal operasional belum memiliki rentang tanggal yang sah.
        if ($schedule->operational_from === null) {
            return $this->unresolvedResult();
        }

        return DB::transaction(function () use ($schedule): array {
            // TASK-003 Slice 3: Kunci machine terlebih dahulu (urutan ID ascending)
            $machineId = (int) $schedule->checksheetMachine()->firstOrFail()->machine_id;
            $machine = Machine::query()
                ->where('id', $machineId)
                ->lockForUpdate()
                ->firstOrFail();

            // Otoritas Schedule memakai lifecycle_status, bukan boolean legacy saja,
            // agar era PAUSED/ENDED tidak pernah menjadi target mutasi.
            $lockedSchedule = PmSchedule::query()
                ->where('lifecycle_status', 'active')
                ->whereKey($schedule->getKey())
                ->lockForUpdate()
                ->first();

            // BR-PM-LC-001: parent non-live tidak boleh membuat, memulihkan,
            // menghapus, atau menulis ulang occurrence apa pun. Baris tersimpan
            // dipertahankan, bukan diperlakukan sebagai tanggal yang tidak diinginkan.
            if ($lockedSchedule === null
                || ! $this->lifecyclePolicy->isLive('machine', (string) $machine->lifecycle_status)) {
                return $this->dormantResult();
            }

            $assignment = $lockedSchedule->checksheetMachine()->first();
            if ($assignment === null || (int) $assignment->machine_id !== (int) $machine->id) {
                throw new InvalidScheduleTransitionException('Provenance assignment schedule berubah.');
            }
            $lockedSchedule->setRelation('pauseIntervals', $lockedSchedule->pauseIntervals()->lockForUpdate()->get());

            return $this->evaluate($lockedSchedule, $machine, true);
        });
    }

    public function preview(PmSchedule $schedule): array
    {
        // State lifecycle dibaca dari baris tersimpan: instance yang baru dibuat
        // lewat Eloquent belum memuat default kolom lifecycle, sehingga keputusan
        // liveness tidak boleh bergantung pada atribut in-memory.
        $persisted = PmSchedule::query()->find($schedule->getKey());

        // Preview unresolved tidak boleh membaca atau menghitung tanggal.
        if ($persisted === null || $persisted->operational_from === null) {
            return $this->unresolvedResult();
        }

        $machine = $persisted->checksheetMachine()->firstOrFail()->machine()->firstOrFail();

        if ($persisted->lifecycle_status !== 'active'
            || ! $this->lifecyclePolicy->isLive('machine', (string) $machine->lifecycle_status)) {
            return $this->dormantResult();
        }

        return $this->evaluate($persisted, $machine, false);
    }

    /** @return array{examined: int, created: int, restored: int, removed: int, unchanged: int, conflicts: int, unresolved: bool} */
    private function unresolvedResult(): array
    {
        return [
            'examined' => 0,
            'created' => 0,
            'restored' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'conflicts' => 0,
            'unresolved' => true,
        ];
    }

    /**
     * Hasil nol untuk parent non-live: baris tersimpan dipertahankan apa adanya,
     * sehingga ini bukan state "unresolved" melainkan tidak ada mutasi.
     *
     * @return array{examined: int, created: int, restored: int, removed: int, unchanged: int, conflicts: int}
     */
    private function dormantResult(): array
    {
        return [
            'examined' => 0,
            'created' => 0,
            'restored' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'conflicts' => 0,
        ];
    }

    /**
     * @return array{examined: int, created: int, restored: int, removed: int, unchanged: int, conflicts: int}
     */
    private function evaluate(PmSchedule $schedule, Machine $machine, bool $apply): array
    {
        $machineId = (int) $machine->id;

        // BR-PM-LC-002: floor materialisasi baru adalah maksimum jendela
        // perencanaan (ADR-009) dan effective_live_from Machine/Schedule.
        // Floor yang sama dipakai untuk generasi tanggal desired dan batas
        // pelestarian baris tersimpan, sehingga reactivation tidak memicu backlog.
        $floor = $this->lifecyclePolicy->materializationFloor(
            $schedule->operational_from,
            $schedule->start_date,
            $machine->effective_live_from,
            $schedule->effective_live_from,
            BusinessDate::today(),
        );

        if ($floor === null) {
            return $this->unresolvedResult();
        }

        $desiredDates = PmScheduleDateGenerator::generate([
            'frequency_type' => $schedule->frequency_type,
            'weekly_days' => $schedule->weekly_days,
            'monthly_day' => $schedule->monthly_day,
            'start_date' => $floor->toDateString(),
            'generate_until' => $schedule->generate_until,
        ]);
        $desiredDates = array_values(array_filter($desiredDates, fn (string $date): bool => ! $schedule->isPausedOn($date)));
        $missingDates = array_fill_keys($desiredDates, true);

        $dateRowsQuery = PmScheduleDate::query()
            ->withTrashed()
            ->where('pm_schedule_id', $schedule->id)
            ->orderBy('id', 'asc')
            ->withCount([
                'executions as executions_with_trashed_count' => fn (Builder $query) => $query->withTrashed(),
            ]);
        $dateRows = $apply ? $dateRowsQuery->lockForUpdate()->get() : $dateRowsQuery->get();
        $result = [
            'examined' => $dateRows->count(),
            'created' => 0,
            'restored' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'conflicts' => 0,
        ];
        $generatedAt = now();

        foreach ($dateRows as $dateRow) {
            $date = $dateRow->scheduled_date->toDateString();
            if ($schedule->isPausedOn($date)
                && $this->lifecyclePolicy->isMutableOccurrence($dateRow->status, (int) $dateRow->executions_with_trashed_count > 0)) {
                continue;
            }

            // Baris sebelum floor dipertahankan apa adanya: Slice A/LC-002
            // mencegah backlog baru, bukan menghapus/menggeser histori.
            if ($dateRow->scheduled_date->lt($floor)) {
                continue;
            }

            $isDesired = isset($missingDates[$date]);
            $isMutable = in_array($dateRow->status, self::MUTABLE_STATUSES, true)
                && (int) $dateRow->executions_with_trashed_count === 0;

            if (! $isMutable) {
                if ($isDesired) {
                    unset($missingDates[$date]);

                    if ($dateRow->trashed()) {
                        $result['conflicts']++;
                    } else {
                        $result['unchanged']++;
                    }
                }

                continue;
            }

            if (! $isDesired) {
                if ($apply) {
                    $dateRow->forceDelete();
                }
                $result['removed']++;

                continue;
            }

            unset($missingDates[$date]);

            if ($dateRow->trashed()) {
                if ($apply) {
                    $dateRow->forceFill([
                        'machine_id' => $machineId,
                        'status' => 'scheduled',
                        'status_changed_at' => null,
                        'generated_at' => $generatedAt,
                    ]);
                    $dateRow->restore();
                }
                $result['restored']++;

                continue;
            }

            $result['unchanged']++;
        }

        foreach (array_keys($missingDates) as $date) {
            if ($apply) {
                PmScheduleDate::query()->create([
                    'pm_schedule_id' => $schedule->id,
                    'machine_id' => $machineId,
                    'scheduled_date' => $date,
                    'status' => 'scheduled',
                    'status_changed_at' => null,
                    'generated_at' => $generatedAt,
                ]);
            }
            $result['created']++;
        }

        return $result;
    }
}
