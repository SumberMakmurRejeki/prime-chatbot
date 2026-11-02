<?php

namespace App\Services\Report;

use App\Models\PmExecutionItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportPmService
{
    public function __construct(private readonly ReportPmParentQuery $parentQueryBuilder) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *   rows:LengthAwarePaginator,
     *   locations:Collection<int, object>,
     *   machines:Collection<int, object>,
     *   parts:Collection<int, string>,
     *   operators:Collection<int, string>,
     *   summary:array{total:int, scheduled:int, overdue:int, missed:int, in_progress:int, waiting_review:int, approved:int, warning:int}
     * }
     */
    public function list(array $filters): array
    {
        $rows = $this->parentQuery($filters)->paginate(10)->withQueryString();
        $this->loadFrozenChildPreviews($rows, $filters);

        return [
            'rows' => $rows,
            'locations' => $this->locationOptions(),
            'machines' => $this->machineOptions(),
            'parts' => DB::table('pm_execution_items')
                ->whereNotNull('part_name_snapshot')
                ->where('part_name_snapshot', '<>', '')
                ->distinct()
                ->orderBy('part_name_snapshot')
                ->pluck('part_name_snapshot'),
            'operators' => DB::table('pm_executions')
                ->whereNull('deleted_at')
                ->whereNotNull('operator_name_snapshot')
                ->where('operator_name_snapshot', '<>', '')
                ->distinct()
                ->orderBy('operator_name_snapshot')
                ->pluck('operator_name_snapshot'),
            'summary' => $this->summary($filters),
        ];
    }

    /**
     * Memuat data hierarki snapshot hanya untuk execution parent pada halaman aktif.
     *
     * @param  array<string, mixed>  $filters
     */
    private function loadFrozenChildPreviews(LengthAwarePaginator $rows, array $filters): void
    {
        $executionIds = collect($rows->items())
            ->pluck('execution_id')
            ->filter()
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values();

        if ($executionIds->isEmpty()) {
            return;
        }

        $items = PmExecutionItem::query()
            ->whereIn('pm_execution_id', $executionIds)
            ->with('actionResults')
            ->orderBy('id')
            ->get();

        $matchingItemIds = $this->matchingPreviewItemIds($executionIds, $filters);
        $matchingItemIdsByExecution = $matchingItemIds->groupBy('pm_execution_id')
            ->map(fn (Collection $matches): array => $matches->pluck('id')->all());
        $itemsByExecution = $items->groupBy('pm_execution_id');

        foreach ($rows->items() as $row) {
            if (! $row->execution_id) {
                continue;
            }

            $executionItems = $itemsByExecution->get((int) $row->execution_id, collect());
            $matchingIds = $matchingItemIdsByExecution->get((int) $row->execution_id, []);

            if ($matchingIds !== [] || ($filters['part_name'] ?? null)) {
                $executionItems = $executionItems->whereIn('id', $matchingIds);
            }

            $row->execution_child_parts = $executionItems
                ->groupBy(fn (PmExecutionItem $item): int|string => $item->pm_checksheet_part_id ?? 'snapshot:'.($item->part_name_snapshot ?: 'legacy'))
                ->map(fn (Collection $partItems): array => [
                    'name' => $partItems->first()->part_name_snapshot ?: 'Part',
                    'items' => $partItems,
                ])
                ->values();
        }
    }

    /**
     * Ambil ID item yang cocok dengan predikat Part dan Search pada hierarki.
     *
     * @param  Collection<int, int>  $executionIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function matchingPreviewItemIds(Collection $executionIds, array $filters): Collection
    {
        if (! ($filters['part_name'] ?? null) && ! ($filters['search'] ?? null)) {
            return collect();
        }

        $partQuery = DB::table('pm_execution_items')
            ->whereIn('pm_execution_id', $executionIds)
            ->when(
                $filters['part_name'] ?? null,
                fn (Builder $query, string $partName) => $query->where('part_name_snapshot', $partName)
            );

        $matches = (clone $partQuery)
            ->when(
                $filters['search'] ?? null,
                function (Builder $query, string $search): void {
                    $query->where(function (Builder $matches) use ($search): void {
                        $matches->where('part_name_snapshot', 'like', "%{$search}%")
                            ->orWhere('standard_name_snapshot', 'like', "%{$search}%");
                    });
                }
            )
            ->get(['id', 'pm_execution_id']);

        if (($filters['part_name'] ?? null) && ($filters['search'] ?? null)) {
            $unmatchedExecutionIds = $executionIds->diff($matches->pluck('pm_execution_id'));

            // Search pada parent atau Part lain tidak boleh membatalkan fokus Part eksplisit.
            if ($unmatchedExecutionIds->isNotEmpty()) {
                $matches = $matches->concat(
                    $partQuery->whereIn('pm_execution_id', $unmatchedExecutionIds)->get(['id', 'pm_execution_id'])
                );
            }
        }

        return $matches;
    }

    /**
     * Ringkasan membungkus proyeksi parent yang sama dengan tabel sebelum paginator,
     * sehingga filter, scope backlog, status efektif, dan Warning tetap satu sumber.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total:int, scheduled:int, overdue:int, missed:int, in_progress:int, waiting_review:int, approved:int, warning:int}
     */
    private function summary(array $filters): array
    {
        $parents = $this->parentQuery($filters)
            ->reorder()
            ->select([
                'psd.id as schedule_date_id',
                DB::raw('LOWER(COALESCE(pe.status, psd.status)) as effective_status'),
                DB::raw('CASE WHEN EXISTS (SELECT 1 FROM pm_execution_items as warning_item WHERE warning_item.pm_execution_id = pe.id AND warning_item.is_warning = 1) THEN 1 ELSE 0 END as is_warning'),
            ]);

        $counts = DB::query()
            ->fromSub($parents, 'report_pm_parent_scope')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN effective_status = 'scheduled' THEN 1 ELSE 0 END), 0) as scheduled")
            ->selectRaw("COALESCE(SUM(CASE WHEN effective_status = 'overdue' THEN 1 ELSE 0 END), 0) as overdue")
            ->selectRaw("COALESCE(SUM(CASE WHEN effective_status = 'missed' THEN 1 ELSE 0 END), 0) as missed")
            ->selectRaw("COALESCE(SUM(CASE WHEN effective_status = 'in_progress' THEN 1 ELSE 0 END), 0) as in_progress")
            ->selectRaw("COALESCE(SUM(CASE WHEN effective_status = 'waiting_review' THEN 1 ELSE 0 END), 0) as waiting_review")
            ->selectRaw("COALESCE(SUM(CASE WHEN effective_status = 'approved' THEN 1 ELSE 0 END), 0) as approved")
            ->selectRaw('COALESCE(SUM(CASE WHEN is_warning = 1 THEN 1 ELSE 0 END), 0) as warning')
            ->first();

        return [
            'total' => (int) $counts->total,
            'scheduled' => (int) $counts->scheduled,
            'overdue' => (int) $counts->overdue,
            'missed' => (int) $counts->missed,
            'in_progress' => (int) $counts->in_progress,
            'waiting_review' => (int) $counts->waiting_review,
            'approved' => (int) $counts->approved,
            'warning' => (int) $counts->warning,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    public function exportRows(array $filters): Collection
    {
        $rows = $this->baseQuery($filters)->get();

        // Label Action multiple beku disatukan ke baris logis yang sama agar
        // export tetap satu baris per execution item tanpa melipatgandakan grain.
        $itemIds = $rows->pluck('execution_item_id')->filter()->unique()->values();

        if ($itemIds->isNotEmpty()) {
            $labelsByItem = DB::table('pm_execution_item_action_results')
                ->whereIn('pm_execution_item_id', $itemIds)
                ->orderBy('option_position_snapshot')
                ->get(['pm_execution_item_id', 'selected_label_snapshot'])
                ->groupBy('pm_execution_item_id')
                ->map(
                    fn (Collection $results): string => $results
                        ->pluck('selected_label_snapshot')
                        ->implode(', ')
                );

            foreach ($rows as $row) {
                if ($row->execution_item_id !== null && isset($labelsByItem[$row->execution_item_id])) {
                    $row->multiple_action_labels = $labelsByItem[$row->execution_item_id];
                }
            }
        }

        return $rows;
    }

    /**
     * Pilihan filter memakai identitas display scope layar sehingga kontrol
     * tidak membocorkan perubahan master terbaru ke permukaan snapshot.
     *
     * @return Collection<int, object>
     */
    private function machineOptions(): Collection
    {
        return DB::query()
            ->fromSub(
                DB::table('machines as m')
                    ->whereNull('m.deleted_at')
                    ->select('m.id as id')
                    ->selectRaw("COALESCE((SELECT CASE WHEN NULLIF(TRIM(pe.machine_code_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.machine_name_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.location_code_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.location_name_snapshot), '') IS NOT NULL THEN pe.machine_code_snapshot ELSE 'Data historis tidak tersedia (legacy)' END FROM pm_executions as pe WHERE pe.machine_id = m.id AND pe.deleted_at IS NULL ORDER BY pe.id DESC LIMIT 1), m.machine_code) as machine_code"),
                'machine_identity_scope'
            )
            ->select('id', 'machine_code')
            ->distinct()
            ->orderBy('machine_code')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function locationOptions(): Collection
    {
        return DB::query()
            ->fromSub(
                DB::table('locations as l')
                    ->whereNull('l.deleted_at')
                    ->select('l.id as id')
                    ->selectRaw("COALESCE((SELECT CASE WHEN NULLIF(TRIM(pe.machine_code_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.machine_name_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.location_code_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.location_name_snapshot), '') IS NOT NULL THEN pe.location_name_snapshot ELSE 'Data historis tidak tersedia (legacy)' END FROM machines as snapshot_machine INNER JOIN pm_executions as pe ON pe.machine_id = snapshot_machine.id WHERE snapshot_machine.location_id = l.id AND pe.deleted_at IS NULL ORDER BY pe.id DESC LIMIT 1), l.location_name) as location_name"),
                'location_identity_scope'
            )
            ->select('id', 'location_name')
            ->orderBy('location_name')
            ->get();
    }

    /**
     * Kueri layar menjaga satu baris per occurrence; filter detail memakai EXISTS
     * agar pagination dan Warning tidak berlipat. Export tetap memakai baseQuery()
     * pada grain execution item.
     *
     * @param  array<string, mixed>  $filters
     */
    private function parentQuery(array $filters): Builder
    {
        return $this->parentQueryBuilder->build($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters): Builder
    {
        // Histori identitas harus atomik agar transaksi tidak tercampur dengan master terkini.
        $usableSnapshotBundle = "NULLIF(TRIM(pe.machine_code_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.machine_name_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.location_code_snapshot), '') IS NOT NULL AND NULLIF(TRIM(pe.location_name_snapshot), '') IS NOT NULL";

        $query = DB::table('pm_schedule_dates as psd')
            ->join('machines as m', 'm.id', '=', 'psd.machine_id')
            ->leftJoin('locations as l', 'l.id', '=', 'm.location_id')
            ->leftJoin('pm_executions as pe', function ($join): void {
                $join->on('pe.pm_schedule_date_id', '=', 'psd.id')
                    ->whereNull('pe.deleted_at');
            })
            ->leftJoin('pm_execution_items as pei', 'pei.pm_execution_id', '=', 'pe.id')
            ->whereNull('psd.deleted_at')
            ->when(
                $filters['start_date'] ?? null,
                fn (Builder $query, string $startDate) => $query->where('psd.scheduled_date', '>=', $startDate.' 00:00:00')
            )
            ->when(
                $filters['end_date'] ?? null,
                fn (Builder $query, string $endDate) => $query->where('psd.scheduled_date', '<=', $endDate.' 23:59:59')
            )
            ->when($filters['location_id'] ?? null, fn (Builder $query, int $locationId) => $query->where('m.location_id', $locationId))
            ->when($filters['machine_id'] ?? null, fn (Builder $query, int $machineId) => $query->where('psd.machine_id', $machineId))
            ->when($filters['part_name'] ?? null, fn (Builder $query, string $partName) => $query->where('pei.part_name_snapshot', $partName))
            ->when($filters['pic_operator'] ?? null, fn (Builder $query, string $picOperator) => $query->where('pe.operator_name_snapshot', $picOperator))
            ->when($filters['pic_admin'] ?? null, fn (Builder $query, string $picAdmin) => $query->where('pe.approved_by_name_snapshot', $picAdmin))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->whereRaw('LOWER(COALESCE(pe.status, psd.status)) = ?', [strtolower($status)]))
            ->when($filters['warning'] ?? null, function (Builder $query, string $warning): void {
                if ($warning === 'warning') {
                    $query->where('pei.is_warning', true);
                }

                if ($warning === 'normal') {
                    $query->where(function (Builder $warningQuery): void {
                        $warningQuery->whereNull('pei.is_warning')->orWhere('pei.is_warning', false);
                    });
                }
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('m.machine_code', 'like', "%{$search}%")
                        ->orWhere('m.machine_name', 'like', "%{$search}%")
                        ->orWhere('pei.part_name_snapshot', 'like', "%{$search}%")
                        ->orWhere('pei.standard_name_snapshot', 'like', "%{$search}%")
                        ->orWhere('pe.operator_name_snapshot', 'like', "%{$search}%")
                        ->orWhere('pe.approved_by_name_snapshot', 'like', "%{$search}%");
                });
            });

        return $query
            ->select([
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
                'pei.id as execution_item_id',
                'pei.part_name_snapshot',
                'pei.standard_name_snapshot',
                'pei.input_type_snapshot',
                'pei.action_value',
                'pei.number_value',
                'pei.min_value_snapshot',
                'pei.max_value_snapshot',
                'pei.unit_snapshot',
                'pei.is_warning',
                'pei.note as operator_note',
            ])
            ->orderByRaw("CASE WHEN LOWER(COALESCE(pe.status, psd.status)) IN ('overdue', 'missed') THEN 0 ELSE 1 END")
            ->orderByDesc('psd.scheduled_date')
            ->orderBy('m.machine_code')
            ->orderByDesc('psd.id')
            ->orderBy('pei.id');
    }

    public function statusLabel(object $row): string
    {
        $status = strtolower((string) ($row->execution_status ?: $row->schedule_status));

        return match ($status) {
            'scheduled' => 'Scheduled',
            'in_progress' => 'In Progress',
            'waiting_review' => 'Waiting Review',
            'approved' => 'Approved',
            'overdue' => 'Overdue',
            'missed' => 'Missed',
            default => '-',
        };
    }

    public function resultAction(object $row): string
    {
        if ((string) ($row->input_type_snapshot ?? '') !== 'action') {
            return '-';
        }

        // Hasil Action multiple memakai label beku urut posisi; scalar hanya fallback legacy.
        if (isset($row->multiple_action_labels) && $row->multiple_action_labels !== '') {
            return $row->multiple_action_labels;
        }

        return (string) ($row->action_value ?: '-');
    }

    public function resultValue(object $row): string
    {
        $inputType = (string) ($row->input_type_snapshot ?? '');

        if ($inputType === 'number') {
            if ($row->number_value === null) {
                return '-';
            }

            return rtrim(rtrim(number_format((float) $row->number_value, 2, '.', ''), '0'), '.');
        }

        if ($inputType === 'range') {
            if ($row->number_value === null) {
                return '-';
            }

            return rtrim(rtrim(number_format((float) $row->number_value, 2, '.', ''), '0'), '.');
        }

        return '-';
    }

    public function warningLabel(object $row): string
    {
        return (bool) ($row->is_warning ?? false) ? 'Warning' : '-';
    }

    public function dateTimeOrDash(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        if (! $value) {
            return '-';
        }

        try {
            return Carbon::parse((string) $value)->format($format);
        } catch (\Throwable) {
            return '-';
        }
    }
}
