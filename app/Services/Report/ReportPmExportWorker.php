<?php

namespace App\Services\Report;

use App\Exports\PM\ReportPmWorkerExport;
use App\Models\ReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class ReportPmExportWorker
{
    private const FILTER_KEYS = [
        'start_date',
        'end_date',
        'location_id',
        'machine_id',
        'part_name',
        'pic_operator',
        'pic_admin',
        'status',
        'warning',
        'search',
    ];

    private const SCOPE_KEYS = ['period_mode', 'resolved_start_date', 'resolved_end_date', 'include_attention_backlog'];

    /** Tabel fisik yang dibaca predicate parent, detail, dan Action dalam satu snapshot. */
    public const SNAPSHOT_TABLES = [
        'pm_schedule_dates',
        'pm_schedules',
        'pm_executions',
        'pm_execution_items',
        'pm_execution_item_action_results',
        'machines',
        'locations',
    ];

    public function __construct(
        protected ReportPmExportQuery $exportQuery,
        protected ReportPmService $reportPmService,
    ) {}

    public function generate(int $reportExportId): void
    {
        $reportExport = $this->loadProcessingExport($reportExportId);
        [$filters, $scope] = $this->validatedFilterData($reportExport);

        $connection = DB::connection();
        // Dalam pengujian RefreshDatabase selalu ada transaksi harness; artefak tetap
        // hanya dibuat setelah transaksi worker sendiri tertutup. Produksi wajib
        // berjalan tanpa transaksi luar agar batas snapshot worker tidak tercampur.
        if (! app()->runningUnitTests() && $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Worker export PM tidak boleh berjalan di dalam transaksi luar.');
        }

        $spool = null;
        $previousIsolation = null;
        $startingLevel = $connection->transactionLevel();

        try {
            $spool = ReportPmExportSpool::open($reportExportId, [
                'report_export_id' => $reportExportId,
                'worker_started_at' => now()->toIso8601String(),
                'file_type' => $reportExport->file_type,
                'requested_by_name_snapshot' => $reportExport->requested_by_name_snapshot,
                'filters' => $filters,
                'scope' => $scope,
            ]);
            $previousIsolation = $this->prepareConsistentSnapshot($connection);

            try {
                // Baca PM pertama dilakukan setelah transaksi dibuka, sehingga seluruh
                // retrieval memakai satu snapshot konsisten yang sama.
                $connection->beginTransaction();
                $this->spoolRecords($spool, $filters, $scope);
                // Footer dan rename selesai sebelum commit; artifact menyusul setelah commit.
                $readyPath = $spool->finalize();
                $connection->commit();
            } finally {
                while ($connection->transactionLevel() > $startingLevel) {
                    $connection->rollBack();
                }

                $this->restoreIsolation($connection, $previousIsolation);
            }
        } catch (Throwable $exception) {
            if ($spool instanceof ReportPmExportSpool) {
                $this->safeDelete($spool);
            }
            $this->markAsFailed($reportExport, $exception->getMessage());

            throw $exception;
        }

        try {
            $this->storeArtifact($reportExport, $readyPath, $filters);
            $this->safeDelete($spool);
        } catch (Throwable $exception) {
            $this->safeDelete($spool);
            $this->markAsFailed($reportExport, $exception->getMessage());

            throw $exception;
        }
    }

    private function loadProcessingExport(int $reportExportId): ReportExport
    {
        $reportExport = ReportExport::query()->find($reportExportId);

        if (! $reportExport instanceof ReportExport) {
            throw new InvalidArgumentException("Report export {$reportExportId} tidak ditemukan.");
        }

        // Fail closed: ID tidak valid/tidak berstatus processing tidak mengubah baris mana pun.
        if ($reportExport->report_type !== 'pm'
            || ! in_array($reportExport->file_type, ['pdf', 'excel'], true)
            || $reportExport->status !== 'processing') {
            throw new InvalidArgumentException('Report export PM tidak berada dalam state processing yang didukung.');
        }

        return $reportExport;
    }

    /**
     * Bentuk filter_data harus sama dengan hasil B1a registration.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validatedFilterData(ReportExport $reportExport): array
    {
        $filterData = $reportExport->filter_data;

        if (! is_array($filterData)) {
            throw new InvalidArgumentException('filter_data report export PM tidak valid.');
        }

        foreach (self::FILTER_KEYS as $key) {
            if (! array_key_exists($key, $filterData)) {
                throw new InvalidArgumentException("filter_data report export PM kehilangan kunci {$key}.");
            }
        }

        $scope = $filterData['scope'] ?? null;
        if (! is_array($scope)) {
            throw new InvalidArgumentException('scope filter_data report export PM tidak valid.');
        }

        foreach (self::SCOPE_KEYS as $key) {
            if (! array_key_exists($key, $scope)) {
                throw new InvalidArgumentException("scope report export PM kehilangan kunci {$key}.");
            }
        }

        if (! in_array($scope['period_mode'], ['explicit', 'default_month'], true)
            || ! is_bool($scope['include_attention_backlog'])) {
            throw new InvalidArgumentException('scope filter_data report export PM tidak konsisten.');
        }

        if ($scope['period_mode'] === 'default_month'
            && ($scope['resolved_start_date'] === null || $scope['resolved_end_date'] === null)) {
            throw new InvalidArgumentException('scope default_month report export PM tanpa batas tanggal teresolve.');
        }

        return [array_intersect_key($filterData, array_flip(self::FILTER_KEYS)), $scope];
    }

    private function pageSize(string $key): int
    {
        $value = config('report.'.$key);
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value)) {
            // FILTER_VALIDATE_INT menolak nilai non-numerik dan overflow int.
            $size = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($size !== false) {
                return $size;
            }
        }

        throw new RuntimeException("Konfigurasi {$key} harus berupa bilangan bulat positif.");
    }

    /**
     * Retrieval tiga level dengan keyset paging; tidak ada OFFSET.
     * Spool selalu parent-major sesuai urutan Report Screen.
     */
    private function spoolRecords(ReportPmExportSpool $spool, array $filters, array $scope): void
    {
        $parentPageSize = $this->pageSize('pm_export_parent_page_size');
        $detailPageSize = $this->pageSize('pm_export_detail_page_size');
        $actionPageSize = $this->pageSize('pm_export_action_page_size');
        $continuation = null;

        do {
            $parents = $this->exportQuery->parentPage($filters, $scope, $continuation, $parentPageSize);

            foreach ($parents as $parent) {
                $spool->writeParent($this->parentRecord($parent));
                $lastItemId = null;

                if ($parent->execution_id !== null) {
                    do {
                        $details = $this->exportQuery->detailPage((int) $parent->execution_id, $lastItemId, $detailPageSize);
                        $itemIds = array_map(fn (object $detail): int => (int) $detail->execution_item_id, $details);
                        $labels = $this->loadActionLabels($itemIds, $actionPageSize);

                        foreach ($details as $detail) {
                            $itemId = (int) $detail->execution_item_id;
                            $spool->writeDetail($this->detailRecord($parent, $detail, $labels[$itemId] ?? null));
                            $lastItemId = $itemId;
                        }
                    } while (count($details) === $detailPageSize);
                }

                $spool->finishParent();
            }

            if ($parents !== []) {
                $continuation = $this->continuationOf($parents[array_key_last($parents)]);
            }
        } while (count($parents) === $parentPageSize);
    }

    /**
     * Satu halaman action dibaca per halaman detail, dengan continuation sendiri
     * agar setiap label tertulis persis satu kali dalam urutan posisi.
     *
     * @param  list<int>  $itemIds
     * @return array<int, string>
     */
    private function loadActionLabels(array $itemIds, int $pageSize): array
    {
        if ($itemIds === []) {
            return [];
        }

        $labels = [];
        $continuation = null;

        do {
            $actions = $this->exportQuery->actionLabelsFor($itemIds, $continuation, $pageSize);

            foreach ($actions as $action) {
                $labels[(int) $action->pm_execution_item_id][] = (string) $action->selected_label_snapshot;
                $continuation = [
                    'item_id' => (int) $action->pm_execution_item_id,
                    'position' => (int) $action->option_position_snapshot,
                    'id' => (int) $action->id,
                ];
            }
        } while (count($actions) === $pageSize);

        return array_map(fn (array $values): string => implode(', ', $values), $labels);
    }

    /** @return array<string, mixed> */
    private function parentRecord(object $parent): array
    {
        return [
            'schedule_date_id' => (int) $parent->schedule_date_id,
            'scheduled_date' => (string) $parent->scheduled_date,
            'schedule_status' => (string) $parent->schedule_status,
            'machine_code' => $parent->machine_code,
            'machine_name' => $parent->machine_name,
            'location_name' => $parent->location_name,
            'execution_id' => $parent->execution_id === null ? null : (int) $parent->execution_id,
            'execution_status' => $parent->execution_status,
            'operator_name_snapshot' => $parent->operator_name_snapshot,
            'approved_by_name_snapshot' => $parent->approved_by_name_snapshot,
            'submitted_at' => $parent->submitted_at,
            'approved_at' => $parent->approved_at,
            'is_warning' => (bool) $parent->is_warning,
            'has_deleted_execution' => (bool) $parent->has_deleted_execution,
            'schedule_only' => $parent->execution_id === null,
            'status_bucket' => (int) $parent->status_bucket,
            'machine_code_sort' => (string) $parent->machine_code_sort,
        ];
    }

    /** @return array<string, mixed> */
    private function detailRecord(object $parent, object $detail, ?string $labels): array
    {
        $record = [
            'schedule_date_id' => (int) $parent->schedule_date_id,
            'execution_id' => (int) $parent->execution_id,
            'execution_item_id' => (int) $detail->execution_item_id,
            'part_name_snapshot' => $detail->part_name_snapshot,
            'standard_name_snapshot' => $detail->standard_name_snapshot,
            'input_type_snapshot' => $detail->input_type_snapshot,
            'action_value' => $detail->action_value,
            'number_value' => $detail->number_value === null ? null : (string) $detail->number_value,
            'min_value_snapshot' => $detail->min_value_snapshot === null ? null : (string) $detail->min_value_snapshot,
            'max_value_snapshot' => $detail->max_value_snapshot === null ? null : (string) $detail->max_value_snapshot,
            'unit_snapshot' => $detail->unit_snapshot,
            'is_warning' => (bool) $detail->is_warning,
            'operator_note' => $detail->operator_note,
        ];

        if ($labels !== null) {
            $record['multiple_action_labels'] = $labels;
        }

        return $record;
    }

    /**
     * @return array{status_bucket: int, scheduled_date: string, machine_code_sort: string, schedule_date_id: int}
     */
    private function continuationOf(object $parent): array
    {
        return [
            'status_bucket' => (int) $parent->status_bucket,
            'scheduled_date' => (string) $parent->scheduled_date,
            'machine_code_sort' => (string) $parent->machine_code_sort,
            'schedule_date_id' => (int) $parent->schedule_date_id,
        ];
    }

    /**
     * Batas konsistensi wajib: MySQL/InnoDB dengan REPEATABLE READ yang diverifikasi
     * sebelum beginTransaction(). Tidak ada fallback ke level yang lebih lemah.
     */
    private function prepareConsistentSnapshot(ConnectionInterface $connection): ?string
    {
        if ($connection->getDriverName() === 'sqlite') {
            // Hanya :memory: satu koneksi untuk PHPUnit; ini bukan fallback produksi.
            if (! app()->runningUnitTests() || $connection->getDatabaseName() !== ':memory:') {
                throw new RuntimeException('Export PM hanya mengizinkan SQLite :memory: untuk pengujian.');
            }

            return null;
        }

        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Export PM hanya didukung pada koneksi MySQL.');
        }

        $placeholders = implode(', ', array_fill(0, count(self::SNAPSHOT_TABLES), '?'));
        $engines = $connection->select(
            'SELECT LOWER(TABLE_NAME) AS table_name, LOWER(ENGINE) AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('.$placeholders.')',
            self::SNAPSHOT_TABLES,
        );
        $engineByTable = [];
        foreach ($engines as $engine) {
            $engineByTable[(string) $engine->table_name] = strtolower((string) $engine->engine);
        }

        // Satu tabel nontransaksional pun dapat membocorkan commit baru ke dataset beku.
        foreach (self::SNAPSHOT_TABLES as $table) {
            if (($engineByTable[$table] ?? null) !== 'innodb') {
                throw new RuntimeException('Tabel '.$table.' harus tersedia sebagai InnoDB untuk snapshot export PM.');
            }
        }

        $current = $this->isolationLevel($connection);
        if ($current === 'REPEATABLE READ') {
            return null;
        }

        $supportedPreviousLevels = ['READ COMMITTED', 'READ UNCOMMITTED', 'SERIALIZABLE'];
        if (! in_array($current, $supportedPreviousLevels, true)) {
            throw new RuntimeException('Level isolasi sesi MySQL tidak didukung untuk export PM.');
        }

        // SESSION dipakai agar level dapat diverifikasi via @@transaction_isolation;
        // SET TRANSACTION per transaksi tidak terlihat oleh variabel tersebut.
        try {
            $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            if ($this->isolationLevel($connection) !== 'REPEATABLE READ') {
                throw new RuntimeException('Gagal menetapkan REPEATABLE READ untuk export PM.');
            }
        } catch (Throwable $exception) {
            try {
                $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$current);
            } catch (Throwable) {
                // Pertahankan error utama; sesi worker tidak boleh dialihkan ke fallback.
            }

            throw $exception;
        }

        return $current;
    }

    private function isolationLevel(ConnectionInterface $connection): string
    {
        $level = $connection->selectOne('SELECT @@transaction_isolation AS level');

        return str_replace('-', ' ', strtoupper((string) ($level->level ?? '')));
    }

    private function restoreIsolation(ConnectionInterface $connection, ?string $previousIsolation): void
    {
        if ($previousIsolation === null || $previousIsolation === $this->isolationLevel($connection)) {
            return;
        }

        $allowed = ['READ COMMITTED', 'READ UNCOMMITTED', 'SERIALIZABLE'];
        if (! in_array($previousIsolation, $allowed, true)) {
            throw new RuntimeException('Level isolasi sesi sebelumnya tidak dikenal untuk pengembalian state.');
        }

        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$previousIsolation);
    }

    private function storeArtifact(ReportExport $reportExport, string $readyPath, array $filters): void
    {
        $spool = ReportPmExportSpool::openReady($readyPath, (int) $reportExport->id);
        $filename = sprintf('report-pm-%d-%s.%s', $reportExport->id, now()->format('Ymd-His'), $reportExport->file_type === 'pdf' ? 'pdf' : 'xlsx');
        $path = 'exports/'.$filename;

        if ($reportExport->file_type === 'excel') {
            // Disk non-throwing dapat mengembalikan false; completion wajib menunggu sukses.
            if (! Excel::store(new ReportPmWorkerExport($spool, $this->reportPmService), $path, 'public')) {
                throw new RuntimeException('Gagal menyimpan artifact export PM.');
            }
        } else {
            $parentGroups = $this->reconstructParentGroups($spool);
            $pdf = Pdf::loadView('pages.report.pm.pdf', [
                'parentGroups' => $parentGroups,
                'reportPmService' => $this->reportPmService,
                'filters' => $filters,
                'summary' => $this->reconstructSummary($parentGroups),
                'printedBy' => $reportExport->requested_by_name_snapshot,
            ])->setPaper('a4', 'landscape');
            if (! Storage::disk('public')->put($path, $pdf->output())) {
                throw new RuntimeException('Gagal menyimpan artifact export PM.');
            }
        }

        $reportExport->forceFill([
            'status' => 'completed',
            'file_name' => $filename,
            'file_path' => $path,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Grup parent direkonstruksi dengan semantik pmParentGroups(): satu grup per
     * schedule_date_id, parent warning bila ada satu pun detail warning.
     */
    private function reconstructParentGroups(ReportPmExportSpool $spool): Collection
    {
        $groups = collect();

        foreach ($spool->records() as $record) {
            if ($record['type'] === 'parent') {
                $parent = (object) $record;
                $parent->details = collect();
                $groups->push($parent);

                continue;
            }

            $parent = $groups->last();
            $parent->details->push((object) $record);
            $parent->is_warning = $parent->is_warning || (bool) $record['is_warning'];
        }

        return $groups;
    }

    /**
     * Ringkasan PDF dengan semantik pmParentSummary(): hitung per bucket statusLabel.
     *
     * @return array{total:int, warning:int, scheduled:int, in_progress:int, waiting_review:int, approved:int, overdue:int, missed:int}
     */
    private function reconstructSummary(Collection $parentGroups): array
    {
        $countLabel = fn (string $label): int => $parentGroups
            ->filter(fn (object $parent): bool => $this->reportPmService->statusLabel($parent) === $label)
            ->count();

        return [
            'total' => $parentGroups->count(),
            'warning' => $parentGroups->where('is_warning', true)->count(),
            'scheduled' => $countLabel('Scheduled'),
            'in_progress' => $countLabel('In Progress'),
            'waiting_review' => $countLabel('Waiting Review'),
            'approved' => $countLabel('Approved'),
            'overdue' => $countLabel('Overdue'),
            'missed' => $countLabel('Missed'),
        ];
    }

    private function safeDelete(ReportPmExportSpool $spool): void
    {
        try {
            $spool->delete();
        } catch (Throwable) {
            // Cleanup bersifat best-effort dan tidak boleh menutupi error utama.
        }
    }

    private function markAsFailed(ReportExport $reportExport, string $message): void
    {
        $reportExport->forceFill([
            'status' => 'failed',
            'failed_message' => mb_substr($message, 0, 1000),
            'completed_at' => null,
        ])->save();
    }
}
