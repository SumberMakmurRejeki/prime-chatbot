<?php

namespace App\Services\Dashboard;

use App\Http\Requests\Dashboard\DashboardFilterRequest;
use App\Models\Breakdown;
use App\Models\Machine;
use App\Models\PmScheduleDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Satu sumber kontrak data Dashboard (Inertia/Vue /dashboard dan permukaan
 * pembanding /internal/dashboard-vue). Semua rumus monitoring tetap milik
 * Laravel dan tidak boleh diduplikasi ke lapisan presentasi.
 */
class DashboardMonitoringService
{
    /**
     * Normalisasi filter tanggal: default awal bulan s/d hari ini, awal hari dan
     * akhir hari agar seluruh query inklusif pada batas rentang.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function normalizedPeriod(DashboardFilterRequest $request): array
    {
        $normalizedRange = $request->normalizedDateRange();

        return [
            'start' => Carbon::createFromFormat('Y-m-d', $normalizedRange['start_date'])->startOfDay(),
            'end' => Carbon::createFromFormat('Y-m-d', $normalizedRange['end_date'])->endOfDay(),
        ];
    }

    /**
     * Periode default bawaan server (awal bulan berjalan s/d hari ini).
     * Dipakai kandidat Vue agar tombol Reset tidak menghitung tanggal di sisi browser.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function defaultPeriod(): array
    {
        return [
            'start' => now()->startOfMonth()->startOfDay(),
            'end' => now()->endOfDay(),
        ];
    }

    /**
     * Payload monitoring lengkap. Bila perhitungan gagal, seluruh konsumen
     * mendapat payload cadangan nol/kosong plus pesan aman `loadErrorMessage`.
     *
     * @return array<string, mixed>
     */
    public function payload(Carbon $startDate, Carbon $endDate): array
    {
        $dashboardPayload = [
            'machineSummary' => [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
            ],
            'pmStatus' => [
                'completed' => 0,
                'overdue' => 0,
                'total' => 0,
            ],
            'mtbfHours' => 0.0,
            'mtbrHours' => 0.0,
            'pmRateSeries' => collect(),
            'breakdownDurationSeries' => collect(),
            'closedBreakdownLogs' => collect(),
            'loadErrorMessage' => null,
        ];

        try {
            $closedBreakdowns = $this->closedBreakdowns($startDate, $endDate);

            return [
                'machineSummary' => $this->machineSummary(),
                'pmStatus' => $this->pmStatus($startDate, $endDate),
                'mtbfHours' => $this->calculateMtbfHours($closedBreakdowns),
                'mtbrHours' => $this->calculateMtbrHours($closedBreakdowns),
                'pmRateSeries' => $this->pmRateSeries($startDate, $endDate),
                'breakdownDurationSeries' => $this->breakdownDurationSeries($closedBreakdowns),
                'closedBreakdownLogs' => $this->closedBreakdownLogs($closedBreakdowns),
                'loadErrorMessage' => null,
            ];
        } catch (Throwable $exception) {
            Log::error('Dashboard monitoring failed to load.', [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'error' => $exception->getMessage(),
            ]);

            $dashboardPayload['loadErrorMessage'] = 'Data monitoring gagal dimuat. Silakan coba lagi.';

            return $dashboardPayload;
        }
    }

    protected function machineSummary(): array
    {
        $total = Machine::query()->count();
        $active = Machine::query()->active()->count();
        $inactive = Machine::query()->inactive()->count();

        return compact('total', 'active', 'inactive');
    }

    /**
     * @return array{completed: int, overdue: int, total: int}
     */
    protected function pmStatus(Carbon $startDate, Carbon $endDate): array
    {
        $scheduleDates = PmScheduleDate::query()
            ->withoutPauseGap()
            ->whereBetween('scheduled_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get(['status']);

        $completed = $scheduleDates->whereIn('status', ['approved', 'waiting_review'])->count();
        $overdue = $scheduleDates->whereIn('status', ['overdue', 'missed'])->count();

        return [
            'completed' => $completed,
            'overdue' => $overdue,
            'total' => $completed + $overdue,
        ];
    }

    /**
     * Durasi rata-rata antar kejadian breakdown (MTBF) hanya dihitung dari
     * breakdown closed pada rentang dengan `breakdown_at` yang valid.
     */
    protected function calculateMtbfHours(Collection $closedBreakdowns): float
    {
        $orderedByStart = $closedBreakdowns
            ->filter(fn (Breakdown $breakdown): bool => $breakdown->breakdown_at !== null)
            ->sortBy('breakdown_at')
            ->values();

        if ($orderedByStart->count() < 2) {
            return 0.0;
        }

        $intervalHours = collect();

        for ($index = 1; $index < $orderedByStart->count(); $index++) {
            /** @var Breakdown $previous */
            $previous = $orderedByStart->get($index - 1);
            /** @var Breakdown $current */
            $current = $orderedByStart->get($index);

            $intervalHours->push($previous->breakdown_at->diffInMinutes($current->breakdown_at) / 60);
        }

        return round($intervalHours->avg() ?: 0.0, 1);
    }

    /**
     * MTBR: rata-rata downtime (jam) breakdown closed pada rentang terpilih.
     */
    protected function calculateMtbrHours(Collection $closedBreakdowns): float
    {
        if ($closedBreakdowns->isEmpty()) {
            return 0.0;
        }

        return round($closedBreakdowns->avg(fn (Breakdown $breakdown): float => ($breakdown->downtime_minutes ?? 0) / 60), 1);
    }

    /**
     * @return Collection<int, array{month_key: string, month_label: string, target_percentage: float, actual_percentage: float}>
     */
    protected function pmRateSeries(Carbon $startDate, Carbon $endDate): Collection
    {
        $scheduleByMonth = PmScheduleDate::query()
            ->withoutPauseGap()
            ->select(['scheduled_date', 'status'])
            ->whereBetween('scheduled_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->groupBy(fn (PmScheduleDate $scheduleDate): string => Carbon::parse((string) $scheduleDate->scheduled_date)->format('Y-m'));

        $periodMonths = collect();
        $cursor = $startDate->copy()->startOfMonth();
        $lastMonth = $endDate->copy()->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $periodMonths->push($cursor->format('Y-m'));
            $cursor->addMonth();
        }

        return $periodMonths->map(function (string $monthKey) use ($scheduleByMonth): array {
            $monthRows = $scheduleByMonth->get($monthKey, collect());
            $totalSchedules = $monthRows->count();
            $submittedSchedules = $monthRows->whereIn('status', ['waiting_review', 'approved'])->count();
            $actualPercent = $totalSchedules > 0 ? round(($submittedSchedules / $totalSchedules) * 100, 1) : 0.0;
            $targetPercent = $totalSchedules > 0 ? 100.0 : 0.0;

            return [
                'month_key' => $monthKey,
                'month_label' => Carbon::createFromFormat('Y-m', $monthKey)->translatedFormat('M'),
                'target_percentage' => $targetPercent,
                'actual_percentage' => $actualPercent,
            ];
        });
    }

    /**
     * @return Collection<int, Breakdown>
     */
    protected function closedBreakdowns(Carbon $startDate, Carbon $endDate): Collection
    {
        return Breakdown::query()
            ->with(['machine:id,machine_name'])
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$startDate, $endDate])
            ->orderBy('closed_at')
            ->get();
    }

    /**
     * @return Collection<int, array{part_name: string, total_duration_hours: float, total_breakdown_count: int}>
     */
    protected function breakdownDurationSeries(Collection $closedBreakdowns): Collection
    {
        if ($closedBreakdowns->isEmpty()) {
            return collect();
        }

        return $closedBreakdowns
            ->groupBy(fn (Breakdown $breakdown): string => $breakdown->part_name_snapshot ?: $breakdown->custom_part_name ?: 'Part')
            ->map(function (Collection $items, string $partName): array {
                return [
                    'part_name' => $partName,
                    'total_duration_hours' => round($items->sum(fn (Breakdown $breakdown): float => ($breakdown->downtime_minutes ?? 0) / 60), 1),
                    'total_breakdown_count' => $items->count(),
                ];
            })
            ->sortByDesc('total_duration_hours')
            ->values();
    }

    /**
     * @return Collection<int, array{title: string, machine_name: string, part_name: string, closed_date_label: string, status: string}>
     */
    protected function closedBreakdownLogs(Collection $closedBreakdowns): Collection
    {
        if ($closedBreakdowns->isEmpty()) {
            return collect();
        }

        return $closedBreakdowns
            ->sortByDesc('closed_at')
            ->map(function (Breakdown $breakdown): array {
                $machineName = $breakdown->machine?->machine_name ?? $breakdown->machine_name_snapshot ?? 'Mesin Tanpa Nama';
                $title = (string) ($breakdown->problem ?: ($breakdown->part_name_snapshot ?: 'Breakdown Closed'));
                $partName = (string) ($breakdown->part_name_snapshot ?: ($breakdown->custom_part_name ?: 'Part'));

                return [
                    'title' => $title,
                    'machine_name' => $machineName,
                    'part_name' => $partName,
                    'closed_date_label' => optional($breakdown->closed_at)?->translatedFormat('d M Y') ?? '-',
                    'status' => 'Closed',
                ];
            })
            ->values();
    }
}
