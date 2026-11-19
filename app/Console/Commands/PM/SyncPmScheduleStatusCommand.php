<?php

namespace App\Console\Commands\PM;

use App\Models\Machine;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Services\Notification\AdminNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SyncPmScheduleStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pm:sync-schedule-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronisasi status PM schedule menjadi overdue/missed dan buat notifikasi admin tanpa duplikasi';

    public function __construct(
        protected AdminNotificationService $notificationService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = now()->startOfDay();
        $overdueCount = 0;
        $missedCount = 0;

        // Memproses scheduled → overdue. Query mengambil semua scheduled sebelum hari ini
        // agar stale occurrence tidak stuck selamanya. Occurrence dengan execution
        // (termasuk soft-deleted) tidak ikut dimutasi agar pekerjaan yang sudah dimulai
        // tidak terganggu cron
        /** @var Collection<int, PmScheduleDate> $overdueSchedules */
        $overdueSchedules = $this->constrainToAgingSchedule(PmScheduleDate::query())
            ->with('machine')
            ->where('scheduled_date', '<', $today->toDateString())
            ->where('status', 'scheduled')
            ->whereDoesntHave('executions', function ($query): void {
                $query->withTrashed();
            })
            ->get();

        // Batas tanggal untuk membedakan normal overdue (kemarin) dan catch-up (sebelum kemarin).
        $yesterdayString = $today->copy()->subDay()->toDateString();

        foreach ($overdueSchedules as $scheduleDate) {
            // Lock lifecycle, CAS, dan notifikasi berada dalam satu transaksi:
            // Pause menunggu commit, sedangkan kegagalan notifikasi membatalkan aging.
            $transitioned = DB::transaction(function () use ($scheduleDate, $yesterdayString): bool {
                if (! $this->transitionIfStillEligible($scheduleDate, 'scheduled', 'overdue')) {
                    return false;
                }

                // Catch-up intermediate tidak menghasilkan pm_overdue.
                if ($scheduleDate->scheduled_date->toDateString() >= $yesterdayString) {
                    $this->notificationService->createUnique(
                        notificationType: 'pm_overdue',
                        title: sprintf(
                            'Pengingat: Jadwal PM Mesin %s Melewati Batas Waktu',
                            (string) ($scheduleDate->machine?->machine_name ?? '-'),
                        ),
                        message: sprintf(
                            'Hai, mengingatkan bahwa jadwal Preventive Maintenance untuk mesin %s saat ini telah melewati tanggal pengerjaan yang ditentukan. Mohon agar dapat segera ditindaklanjuti.',
                            (string) ($scheduleDate->machine?->machine_name ?? '-'),
                        ),
                        relatedTable: 'pm_schedule_dates',
                        relatedId: $scheduleDate->id,
                        targetUrl: $scheduleDate->machine ? '/machines/'.$scheduleDate->machine->machine_code : '/calendar',
                        data: [
                            'machine_id' => $scheduleDate->machine_id,
                            'machine_code' => (string) ($scheduleDate->machine?->machine_code ?? ''),
                            'scheduled_date' => (string) optional($scheduleDate->scheduled_date)?->toDateString(),
                        ],
                    );
                }

                return true;
            });

            if ($transitioned) {
                $overdueCount++;
            }
        }

        // Memproses overdue → missed, termasuk catch-up occurrence yang baru saja
        // menjadi overdue pada loop pertama. Query dilakukan ulang agar stale scheduled
        // yang sudah berubah menjadi overdue tetap terproses dalam satu command run.
        /** @var Collection<int, PmScheduleDate> $missedSchedules */
        $missedSchedules = $this->constrainToAgingSchedule(PmScheduleDate::query())
            ->with('machine')
            ->where('scheduled_date', '<', $today->copy()->subDay()->toDateString())
            ->where('status', 'overdue')
            ->whereDoesntHave('executions', function ($query): void {
                $query->withTrashed();
            })
            ->get();

        foreach ($missedSchedules as $scheduleDate) {
            $transitioned = DB::transaction(function () use ($scheduleDate): bool {
                if (! $this->transitionIfStillEligible($scheduleDate, 'overdue', 'missed')) {
                    return false;
                }

                $this->notificationService->createUnique(
                    notificationType: 'pm_missed',
                    title: sprintf(
                        'Pembaruan Status: Jadwal PM Mesin %s Tidak Terlaksana (Missed)',
                        (string) ($scheduleDate->machine?->machine_name ?? '-'),
                    ),
                    message: sprintf(
                        'Mohon perhatiannya, jadwal Preventive Maintenance untuk mesin %s kini berstatus Missed karena telah melewati batas waktu pengerjaan. Pastikan operator mengerjakan PM sesuai jadwal yang ditentukan.',
                        (string) ($scheduleDate->machine?->machine_name ?? '-'),
                    ),
                    relatedTable: 'pm_schedule_dates',
                    relatedId: $scheduleDate->id,
                    targetUrl: $scheduleDate->machine ? '/machines/'.$scheduleDate->machine->machine_code : '/calendar',
                    data: [
                        'machine_id' => $scheduleDate->machine_id,
                        'machine_code' => (string) ($scheduleDate->machine?->machine_code ?? ''),
                        'scheduled_date' => (string) optional($scheduleDate->scheduled_date)?->toDateString(),
                    ],
                );

                return true;
            });

            if ($transitioned) {
                $missedCount++;
            }
        }

        $this->info(sprintf('PM schedule disinkronkan. Overdue: %d, Missed: %d', $overdueCount, $missedCount));

        return self::SUCCESS;
    }

    /**
     * Dipanggil di dalam transaksi yang juga menyimpan notifikasi. Urutan lock
     * Machine -> Schedule sama dengan writer lifecycle dan menahan Pause/PM start
     * sampai transisi selesai. State Schedule dibaca ulang di bawah lock;
     * CAS tetap melindungi occurrence stale dan execution termasuk soft-deleted.
     */
    private function transitionIfStillEligible(
        PmScheduleDate $scheduleDate,
        string $expectedStatus,
        string $nextStatus,
    ): bool {
        $machine = Machine::withTrashed()
            ->whereKey($scheduleDate->machine_id)
            ->lockForUpdate()
            ->first();

        if ($machine === null) {
            return false;
        }

        $schedule = PmSchedule::withTrashed()
            ->whereKey($scheduleDate->pm_schedule_id)
            ->lockForUpdate()
            ->first();

        if ($schedule === null
            || in_array($schedule->lifecycle_status, ['paused', 'ended'], true)
            || ! $schedule->checksheetMachine()->where('machine_id', $machine->id)->exists()) {
            return false;
        }

        $affected = PmScheduleDate::query()
            ->whereKey($scheduleDate->getKey())
            ->where('pm_schedule_id', $schedule->id)
            ->where('machine_id', $machine->id)
            ->where('status', $expectedStatus)
            ->whereDate('scheduled_date', $scheduleDate->scheduled_date->toDateString())
            ->whereDoesntHave('executions', function ($query): void {
                $query->withTrashed();
            })
            ->update([
                'status' => $nextStatus,
                'status_changed_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * PAUSED membekukan aging assignment ini saja; ENDED tetap tidak menua.
     * withTrashed mempertahankan perilaku era soft-deleted. Filter seleksi ini
     * hanya optimasi; keputusan final memakai Schedule terkunci sebelum CAS.
     */
    private function constrainToAgingSchedule(Builder $query): Builder
    {
        return $query->whereHas('schedule', function (Builder $scheduleQuery): void {
            $scheduleQuery->withTrashed()->whereNotIn('lifecycle_status', ['paused', 'ended']);
        });
    }
}
