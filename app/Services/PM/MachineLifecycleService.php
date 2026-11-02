<?php

namespace App\Services\PM;

use App\Models\Machine;
use App\Models\PmSchedule;
use App\Services\Auth\ActivityLogService;
use Illuminate\Support\Facades\DB;

/**
 * Otoritas tunggal mutasi lifecycle Machine untuk transisi ordinary dan retirement.
 *
 * Layanan ini menjaga urutan lock Machine -> PmSchedule, compatibility projection
 * is_active, dan audit atomik tanpa menulis ulang bukti historis PM.
 */
class MachineLifecycleService
{
    public function __construct(
        protected LifecyclePolicy $lifecyclePolicy,
        protected ActivityLogService $activityLog,
        protected ScheduleLifecycleService $scheduleLifecycleService,
        protected PmScheduleDateReconciler $scheduleDateReconciler,
    ) {}

    /**
     * Menerapkan satu transisi lifecycle Machine secara transaksional.
     *
     * Tidak ada reason teks dari pengguna: konfirmasi eksplisit pada UI sudah
     * cukup untuk kontrak V1. Jejak audit tetap otomatis melalui actor,
     * timestamp, dan snapshot old/new state di user_activity_logs.
     *
     * @throws InvalidMachineTransitionException Jika transisi tidak legal.
     */
    public function transition(Machine $machine, string $target): Machine
    {
        return DB::transaction(function () use ($machine, $target): Machine {
            // Machine authoritative selalu dikunci lebih dahulu sesuai kontrak lock Slice C.
            /** @var Machine $locked */
            $locked = Machine::query()->lockForUpdate()->findOrFail($machine->id);
            $from = (string) $locked->lifecycle_status;

            if (! $this->lifecyclePolicy->canTransition('machine', $from, $target)) {
                throw new InvalidMachineTransitionException(sprintf(
                    "Transisi lifecycle machine dari '%s' ke '%s' tidak diizinkan.",
                    $from,
                    $target,
                ));
            }

            // Retirement mengakhiri era schedule non-terminal secara atomik setelah
            // seluruh baris schedule dikunci berurutan untuk menghindari deadlock.
            if ($target === 'retired') {
                $schedules = PmSchedule::query()
                    ->whereNull('deleted_at')
                    ->whereHas('checksheetMachine', fn ($query) => $query->where('machine_id', $locked->id))
                    ->whereIn('lifecycle_status', ['active', 'paused'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($schedules as $schedule) {
                    // Pensiun Machine adalah aksi sistem: schedule diakhiri tanpa
                    // reason teks pengguna, sesuai kontrak V1 tanpa reason wajib.
                    $this->scheduleLifecycleService->transition($schedule, 'ended');
                }
            }

            $oldValues = $locked->only(['lifecycle_status', 'is_active', 'effective_live_from']);
            $attributes = [
                // is_active tetap hanya compatibility projection dari lifecycle_status.
                'lifecycle_status' => $target,
                'is_active' => $target === 'active',
            ];

            if ($from === 'inactive' && $target === 'active') {
                // Reactivation ordinary menandai boundary materialisasi baru tanpa mengubah histori.
                $attributes['effective_live_from'] = BusinessDate::today();
            }

            $locked->forceFill($attributes)->save();

            // Audit berada dalam transaksi yang sama agar kegagalan audit me-roll back state.
            // description dibiarkan null karena tidak ada reason pengguna; actor,
            // action, timestamp, dan old/new state tetap terekam otomatis.
            $this->activityLog->log(
                request: request(),
                moduleName: 'master_mesin',
                action: 'lifecycle_transition',
                tableName: 'machines',
                recordId: $locked->id,
                oldValues: $oldValues,
                newValues: $locked->only(['lifecycle_status', 'is_active', 'effective_live_from']),
            );

            // BR-PM-LC-002: reactivation ordinary WAJIB mematerialisasi occurrence
            // secara sinkron pada transaksi yang sama. Tidak ada cron/event lain yang
            // menjamin occurrence baru dibuat setelah effective_live_from bergeser,
            // sehingga kegagalan reconciler harus me-roll back reactivation.
            if ($from === 'inactive' && $target === 'active') {
                $activeSchedules = PmSchedule::query()
                    ->whereNull('deleted_at')
                    ->whereHas('checksheetMachine', fn ($query) => $query->where('machine_id', $locked->id))
                    ->where('lifecycle_status', 'active')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($activeSchedules as $schedule) {
                    $this->scheduleDateReconciler->reconcile($schedule);
                }
            }

            return $locked;
        });
    }
}
