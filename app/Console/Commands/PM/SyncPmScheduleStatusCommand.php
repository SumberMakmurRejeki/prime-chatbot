<?php

namespace App\Console\Commands\PM;

use App\Models\PmScheduleDate;
use App\Services\Notification\AdminNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
        $overdueSchedules = $this->constrainToNonEndedSchedule(PmScheduleDate::query())
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
            // CAS final: occurrence bisa sudah berpindah ke in_progress oleh PM start
            // setelah query pemilihan, sehingga model stale tidak boleh ditulis.
            if (! $this->transitionIfStillEligible($scheduleDate, 'scheduled', 'overdue')) {
                continue;
            }

            // Hanya buat pm_overdue untuk normal transition (kemarin). Catch-up intermediate
            // tidak menghasilkan pm_overdue karena akan langsung diproses menjadi missed.
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

            $overdueCount++;
        }

        // Memproses overdue → missed, termasuk catch-up occurrence yang baru saja
        // menjadi overdue pada loop pertama. Query dilakukan ulang agar stale scheduled
        // yang sudah berubah menjadi overdue tetap terproses dalam satu command run.
        /** @var Collection<int, PmScheduleDate> $missedSchedules */
        $missedSchedules = $this->constrainToNonEndedSchedule(PmScheduleDate::query())
            ->with('machine')
            ->where('scheduled_date', '<', $today->copy()->subDay()->toDateString())
            ->where('status', 'overdue')
            ->whereDoesntHave('executions', function ($query): void {
                $query->withTrashed();
            })
            ->get();

        foreach ($missedSchedules as $scheduleDate) {
            // Guard yang sama mencegah stale overdue menimpa lifecycle yang sudah aktif.
            if (! $this->transitionIfStillEligible($scheduleDate, 'overdue', 'missed')) {
                continue;
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

            $missedCount++;
        }

        $this->info(sprintf('PM schedule disinkronkan. Overdue: %d, Missed: %d', $overdueCount, $missedCount));

        return self::SUCCESS;
    }

    /**
     * Guard CAS authoritative untuk transisi lifecycle cron.
     *
     * Keputusan eligibility dibuat saat query pemilihan, tetapi row dapat berubah
     * sebelum penulisan — mis. PmExecutionService::startExecutionOnly() commit
     * in_progress lebih dulu. Update conditional ini menjadi keputusan final:
     * nol row berarti cron kalah race, sehingga occurrence, notifikasi, dan counter
     * tidak boleh disentuh. Predikat execution memakai withTrashed() agar histori
     * soft-deleted tetap memblokir transisi.
     */
    private function transitionIfStillEligible(
        PmScheduleDate $scheduleDate,
        string $expectedStatus,
        string $nextStatus,
    ): bool {
        $affected = $this->constrainToNonEndedSchedule(PmScheduleDate::query())
            ->whereKey($scheduleDate->getKey())
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
     * BR-PM-LC-003: aging status hanya berlaku untuk era Schedule yang belum
     * berakhir. Predikat sengaja memakai `<> 'ended'` (bukan active()/currentEra())
     * agar era PAUSED tetap menua seperti perilaku saat ini, dan memakai
     * withTrashed() agar schedule yang di-soft-delete tidak menyembunyikan era
     * non-terminal. Predikat yang sama wajib dipakai pada query pemilihan maupun
     * CAS final, sebab retirement dapat mengakhiri Schedule setelah seleksi.
     */
    private function constrainToNonEndedSchedule(Builder $query): Builder
    {
        return $query->whereHas('schedule', function (Builder $scheduleQuery): void {
            $scheduleQuery->withTrashed()->where('lifecycle_status', '<>', 'ended');
        });
    }
}
