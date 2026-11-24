<?php

namespace App\Services\Report;

use App\Models\PmScheduleDate;
use App\Services\PM\BusinessDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportPmParentQuery
{
    /**
     * Menyusun predicate dan proyeksi parent yang dipakai layar serta worker.
     * Scope null memakai default dinamis layar; scope terisi memakai batas B1a.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $scope
     */
    public function build(array $filters, ?array $scope = null, bool $includeContinuationColumns = false): Builder
    {
        $usableSnapshotBundle = "NULLIF(TRIM(pe.machine_code_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.machine_name_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.location_code_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.location_name_snapshot), '') IS NOT NULL";
        $effectiveStatus = 'LOWER(COALESCE(pe.status, psd.status))';

        $query = DB::table('pm_schedule_dates as psd')
            ->join('machines as m', 'm.id', '=', 'psd.machine_id')
            ->leftJoin('locations as l', 'l.id', '=', 'm.location_id')
            ->leftJoin('pm_executions as pe', function ($join): void {
                $join->on('pe.pm_schedule_date_id', '=', 'psd.id')
                    ->whereNull('pe.deleted_at');
            })
            ->whereNull('psd.deleted_at')
            ->when(
                $filters['start_date'] ?? null,
                fn (Builder $builder, string $startDate) => $builder->where('psd.scheduled_date', '>=', $startDate.' 00:00:00')
            )
            ->when(
                $filters['end_date'] ?? null,
                fn (Builder $builder, string $endDate) => $builder->where('psd.scheduled_date', '<=', $endDate.' 23:59:59')
            )
            ->when($filters['location_id'] ?? null, fn (Builder $builder, int $locationId) => $builder->where('m.location_id', $locationId))
            ->when($filters['machine_id'] ?? null, fn (Builder $builder, int $machineId) => $builder->where('psd.machine_id', $machineId))
            ->when($filters['part_name'] ?? null, function (Builder $builder, string $partName): void {
                $builder->whereExists(function (Builder $items) use ($partName): void {
                    $items->selectRaw('1')
                        ->from('pm_execution_items as pei')
                        ->whereColumn('pei.pm_execution_id', 'pe.id')
                        ->where('pei.part_name_snapshot', $partName);
                });
            })
            ->when($filters['pic_operator'] ?? null, fn (Builder $builder, string $picOperator) => $builder->where('pe.operator_name_snapshot', $picOperator))
            ->when($filters['pic_admin'] ?? null, fn (Builder $builder, string $picAdmin) => $builder->where('pe.approved_by_name_snapshot', $picAdmin))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->whereRaw($effectiveStatus.' = ?', [strtolower($status)]))
            ->when($filters['warning'] ?? null, function (Builder $builder, string $warning): void {
                $warningItems = fn (Builder $items): Builder => $items->selectRaw('1')
                    ->from('pm_execution_items as pei')
                    ->whereColumn('pei.pm_execution_id', 'pe.id')
                    ->where('pei.is_warning', true);

                if ($warning === 'warning') {
                    $builder->whereExists($warningItems);
                }

                if ($warning === 'normal') {
                    $builder->whereNotExists($warningItems);
                }
            })
            ->when($filters['search'] ?? null, function (Builder $builder, string $search): void {
                $builder->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('m.machine_code', 'like', "%{$search}%")
                        ->orWhere('m.machine_name', 'like', "%{$search}%")
                        ->orWhere('pe.operator_name_snapshot', 'like', "%{$search}%")
                        ->orWhere('pe.approved_by_name_snapshot', 'like', "%{$search}%")
                        ->orWhereExists(function (Builder $items) use ($search): void {
                            $items->selectRaw('1')
                                ->from('pm_execution_items as pei')
                                ->whereColumn('pei.pm_execution_id', 'pe.id')
                                ->where(function (Builder $details) use ($search): void {
                                    $details->where('pei.part_name_snapshot', 'like', "%{$search}%")
                                        ->orWhere('pei.standard_name_snapshot', 'like', "%{$search}%");
                                });
                        });
                });
            });

        $this->applyScope($query, $filters, $scope, $effectiveStatus);

        $query->select([
            'psd.id as schedule_date_id',
            'psd.scheduled_date',
            'psd.status as schedule_status',
            DB::raw("CASE WHEN pe.id IS NULL THEN m.machine_code WHEN {$usableSnapshotBundle} THEN pe.machine_code_snapshot ELSE 'Data historis tidak tersedia (legacy)' END as machine_code"),
            DB::raw("CASE WHEN pe.id IS NULL THEN m.machine_name WHEN {$usableSnapshotBundle} THEN pe.machine_name_snapshot ELSE 'Data historis tidak tersedia (legacy)' END as machine_name"),
            DB::raw("CASE WHEN pe.id IS NULL THEN l.location_name WHEN {$usableSnapshotBundle} THEN pe.location_name_snapshot ELSE 'Data historis tidak tersedia (legacy)' END as location_name"),
            'pe.id as execution_id',
            'pe.status as execution_status',
            'pe.operator_name_snapshot',
            'pe.approved_by_name_snapshot',
            'pe.submitted_at',
            'pe.approved_at',
            DB::raw('CASE WHEN EXISTS (SELECT 1 FROM pm_execution_items as warning_item WHERE warning_item.pm_execution_id = pe.id AND warning_item.is_warning = 1) THEN 1 ELSE 0 END as is_warning'),
            DB::raw('CASE WHEN EXISTS (SELECT 1 FROM pm_executions as deleted_execution WHERE deleted_execution.pm_schedule_date_id = psd.id AND deleted_execution.deleted_at IS NOT NULL) THEN 1 ELSE 0 END as has_deleted_execution'),
        ]);

        if ($includeContinuationColumns) {
            $query->addSelect(
                DB::raw("CASE WHEN {$effectiveStatus} IN ('overdue', 'missed') THEN 0 ELSE 1 END as status_bucket"),
                'm.machine_code as machine_code_sort',
            );
        }

        return $query
            ->orderByRaw("CASE WHEN {$effectiveStatus} IN ('overdue', 'missed') THEN 0 ELSE 1 END")
            ->orderByDesc('psd.scheduled_date')
            ->orderBy('m.machine_code')
            ->orderByDesc('psd.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $scope
     */
    private function applyScope(Builder $query, array $filters, ?array $scope, string $effectiveStatus): void
    {
        if (($filters['start_date'] ?? null) || ($filters['end_date'] ?? null)) {
            return;
        }

        PmScheduleDate::constrainPauseGap($query, 'psd');

        if ($scope === null) {
            $today = BusinessDate::today();
            $start = $today->copy()->startOfMonth()->toDateString();
            $end = $today->copy()->endOfMonth()->toDateString();
        } else {
            $start = $scope['resolved_start_date'] ?? null;
            $end = $scope['resolved_end_date'] ?? null;
            if (($scope['period_mode'] ?? null) !== 'default_month'
                || ! ($scope['include_attention_backlog'] ?? false)
                || ! is_string($start) || ! is_string($end)) {
                throw new InvalidArgumentException('Default PM export scope requires persisted period bounds and backlog intent.');
            }
        }

        $monthStart = $start.' 00:00:00';
        $monthEnd = $end.' 23:59:59';
        $query->where(function (Builder $scopeQuery) use ($effectiveStatus, $monthStart, $monthEnd): void {
            $scopeQuery->whereBetween('psd.scheduled_date', [$monthStart, $monthEnd])
                ->orWhere(function (Builder $older) use ($effectiveStatus, $monthStart): void {
                    $older->where('psd.scheduled_date', '<', $monthStart)
                        ->where(function (Builder $attention) use ($effectiveStatus): void {
                            $attention->whereIn(DB::raw($effectiveStatus), ['in_progress', 'waiting_review'])
                                ->orWhere(function (Builder $unstarted) use ($effectiveStatus): void {
                                    $unstarted->whereIn(DB::raw($effectiveStatus), ['overdue', 'missed'])
                                        ->where('m.lifecycle_status', 'active')
                                        ->whereExists(function (Builder $schedule): void {
                                            $schedule->selectRaw('1')
                                                ->from('pm_schedules as backlog_schedule')
                                                ->whereColumn('backlog_schedule.id', 'psd.pm_schedule_id')
                                                ->whereIn('backlog_schedule.lifecycle_status', ['active', 'paused']);
                                        });
                                });
                        });
                });
        });
    }
}
