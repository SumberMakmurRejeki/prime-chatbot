<?php

namespace Tests\Feature\Feature\Report;

use App\Exports\PM\ReportPmWorkerExport;
use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Report\ReportPmExportRegistrationService;
use App\Services\Report\ReportPmExportSpool;
use App\Services\Report\ReportPmExportWorker;
use App\Services\Report\ReportPmService;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReportPmWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_test_sqlite_connection_is_rejected_by_snapshot_gate(): void
    {
        $worker = app(ReportPmExportWorker::class);
        $method = new \ReflectionMethod($worker, 'prepareConsistentSnapshot');
        $method->setAccessible(true);
        $previousEnvironment = app()->bound('env') ? app('env') : null;
        app()->instance('env', 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SQLite :memory: untuk pengujian');

            $method->invoke($worker, DB::connection());
        } finally {
            app()->instance('env', $previousEnvironment ?? 'testing');
        }
    }

    public function test_in_memory_sqlite_connection_is_allowed_only_under_test_environment(): void
    {
        $worker = app(ReportPmExportWorker::class);
        $method = new \ReflectionMethod($worker, 'prepareConsistentSnapshot');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($worker, DB::connection()));
    }

    public function test_registered_processing_export_writes_bounded_excel_rows_without_dispatching(): void
    {
        Storage::fake('public');
        Queue::fake();
        config([
            'report.pm_export_parent_page_size' => 1,
            'report.pm_export_detail_page_size' => 1,
            'report.pm_export_action_page_size' => 1,
        ]);
        $fixture = $this->createFixture();
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'excel', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
        $fixture['machine']->update(['machine_name' => 'Nama Mesin Terkini']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(ReportPmExportWorker::class)->generate((int) $export->id);

        $export->refresh();
        $this->assertSame('completed', $export->status);
        $this->assertNotNull($export->completed_at);
        $this->assertNotNull($export->file_path);
        Storage::disk('public')->assertExists($export->file_path);
        $sheet = IOFactory::load(Storage::disk('public')->path($export->file_path))->getActiveSheet();
        $rows = $sheet->toArray();
        $this->assertSame(17, count($rows[0]));
        $this->assertSame('Hasil Action', $rows[0][7]);
        $this->assertCount(8, $rows);
        $this->assertSame(['Inspect, Lubricate', '-', '-', '-', '-', '-', '-'], array_column(array_slice($rows, 1), 7));
        $this->assertSame('Mesin Worker', $rows[1][3]);
        $this->assertSame([
            'Part Multi', 'Part Number A', 'Part Number B', 'Part Number C',
            'Part Number D', 'Part Number E', 'Part Number F',
        ], array_column(array_slice($rows, 1), 4));
        $this->assertSame(['-', '42', '42', '42', '42', '42', '42'], array_column(array_slice($rows, 1), 8));
        $this->assertStringNotContainsString('offset', implode("\n", $queries));
        $this->assertSame([], glob(storage_path('framework/report-pm-spool/'.$export->id.'-*')) ?: []);
        Queue::assertNothingPushed();
    }

    public function test_same_second_exports_store_distinct_artifacts(): void
    {
        $this->travelTo('2026-10-08 12:34:56');
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $registration = app(ReportPmExportRegistrationService::class);
        $filters = ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'];
        $first = $registration->register($fixture['admin'], 'excel', $filters);
        $second = $registration->register($fixture['admin'], 'excel', $filters);

        app(ReportPmExportWorker::class)->generate((int) $first->id);
        app(ReportPmExportWorker::class)->generate((int) $second->id);

        $first->refresh();
        $second->refresh();
        $this->assertNotSame($first->file_path, $second->file_path);
        $this->assertStringContainsString((string) $first->id, $first->file_name);
        $this->assertStringContainsString((string) $second->id, $second->file_name);
        Storage::disk('public')->assertExists($first->file_path);
        Storage::disk('public')->assertExists($second->file_path);
    }

    public function test_schedule_only_parent_reaches_pdf_without_fabricated_detail(): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false, withExecution: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'pdf', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
        $captured = null;
        $renderer = \Mockery::mock(DomPdf::class)->makePartial();
        $renderer->shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use (&$captured): bool {
            $captured = $data;

            return $view === 'pages.report.pm.pdf';
        })->andReturnSelf();
        $renderer->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $renderer->shouldReceive('output')->once()->andReturn('%PDF-1.4 test');
        app()->instance('dompdf.wrapper', $renderer);

        app(ReportPmExportWorker::class)->generate((int) $export->id);

        $this->assertSame('completed', $export->fresh()->status);
        $this->assertCount(1, $captured['parentGroups']);
        $this->assertSame([], $captured['parentGroups']->first()->details->all());
        $this->assertSame(1, $captured['summary']['total']);
        $this->view('pages.report.pm.pdf', $captured)->assertSee('Belum ada pelaksanaan PM');
    }

    public function test_default_scope_worker_parent_order_matches_report_screen(): void
    {
        $this->travelTo('2026-10-06 12:00:00');
        Storage::fake('public');
        $fixture = $this->createFixture();
        config([
            'report.pm_export_parent_page_size' => 1,
            'report.pm_export_detail_page_size' => 1,
            'report.pm_export_action_page_size' => 1,
        ]);
        foreach ([
            ['2026-09-30', 'overdue'],
            ['2026-09-25', 'approved'],
        ] as [$date, $status]) {
            PmScheduleDate::query()->create([
                'pm_schedule_id' => $fixture['schedule']->id,
                'machine_id' => $fixture['machine']->id,
                'scheduled_date' => $date,
                'status' => $status,
                'status_changed_at' => now(),
            ]);
        }
        $screenIds = app(ReportPmService::class)->list([])['rows']->getCollection()
            ->pluck('schedule_date_id')->map(fn ($id): int => (int) $id)->all();
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'pdf', []);
        $captured = null;
        $renderer = \Mockery::mock(DomPdf::class)->makePartial();
        $renderer->shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use (&$captured): bool {
            $captured = $data;

            return $view === 'pages.report.pm.pdf';
        })->andReturnSelf();
        $renderer->shouldReceive('setPaper')->once()->andReturnSelf();
        $renderer->shouldReceive('output')->once()->andReturn('%PDF-1.4 test');
        app()->instance('dompdf.wrapper', $renderer);

        app(ReportPmExportWorker::class)->generate((int) $export->id);

        $workerIds = $captured['parentGroups']->pluck('schedule_date_id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame($screenIds, $workerIds);
        $executionGroup = $captured['parentGroups']->firstWhere('schedule_date_id', $fixture['occurrence']->id);
        $numberDetail = $executionGroup->details->firstWhere('input_type_snapshot', 'number');
        $this->assertSame('42', $numberDetail->number_value);
        $this->assertSame('integer', gettype($numberDetail->execution_item_id));
    }

    public function test_worker_uses_frozen_explicit_dates_and_current_status_membership(): void
    {
        $this->travelTo('2026-10-06 12:00:00');
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $fixture['execution']->update(['status' => 'waiting_review']);
        $outside = PmScheduleDate::query()->create([
            'pm_schedule_id' => $fixture['schedule']->id,
            'machine_id' => $fixture['machine']->id,
            'scheduled_date' => '2026-11-04',
            'status' => 'approved',
            'status_changed_at' => now(),
        ]);
        $filters = [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'status' => 'approved',
        ];
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'pdf', $filters);
        $fixture['execution']->update(['status' => 'approved']);
        $this->travelTo('2026-11-15 12:00:00');
        $screenIds = app(ReportPmService::class)->list($filters)['rows']->getCollection()
            ->pluck('schedule_date_id')->map(fn ($id): int => (int) $id)->all();
        $captured = null;
        $renderer = \Mockery::mock(DomPdf::class)->makePartial();
        $renderer->shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use (&$captured): bool {
            $captured = $data;

            return $view === 'pages.report.pm.pdf';
        })->andReturnSelf();
        $renderer->shouldReceive('setPaper')->once()->andReturnSelf();
        $renderer->shouldReceive('output')->once()->andReturn('%PDF-1.4 test');
        app()->instance('dompdf.wrapper', $renderer);

        app(ReportPmExportWorker::class)->generate((int) $export->id);

        $this->assertSame([$fixture['occurrence']->id], $captured['parentGroups']->pluck('schedule_date_id')->all());
        $this->assertSame('2026-10-01', $export->fresh()->filter_data['scope']['resolved_start_date']);
        $this->assertNotContains($outside->id, $captured['parentGroups']->pluck('schedule_date_id')->all());
        $this->assertSame($screenIds, $captured['parentGroups']->pluck('schedule_date_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_schedule_only_export_has_headings_without_detail_rows(): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false, withExecution: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'excel', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);

        app(ReportPmExportWorker::class)->generate((int) $export->id);

        $sheet = IOFactory::load(Storage::disk('public')->path($export->fresh()->file_path))->getActiveSheet();
        $this->assertCount(1, $sheet->toArray());
        $this->assertSame(17, count($sheet->toArray()[0]));
    }

    public function test_zero_matching_parents_produces_a_valid_pdf_with_zero_summary(): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false, withExecution: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'pdf', [
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-31',
        ]);

        app(ReportPmExportWorker::class)->generate((int) $export->id);

        $export->refresh();
        $this->assertSame('completed', $export->status);
        $this->assertStringStartsWith('%PDF-', Storage::disk('public')->get($export->file_path));
        $this->view('pages.report.pm.pdf', [
            'parentGroups' => collect(),
            'reportPmService' => app(ReportPmService::class),
            'filters' => ['start_date' => '2026-12-01', 'end_date' => '2026-12-31'],
            'summary' => ['total' => 0],
            'printedBy' => 'Admin',
        ])->assertSee('Tidak ada data PM untuk filter ini.');
    }

    public function test_ready_excel_consumer_does_not_query_pm_tables(): void
    {
        Storage::fake('public');
        $spool = ReportPmExportSpool::open(910001, ['report_export_id' => 910001]);
        $spool->writeParent([
            'schedule_date_id' => 1, 'scheduled_date' => '2026-10-04', 'schedule_status' => 'approved',
            'execution_id' => 2, 'execution_status' => 'approved', 'machine_code' => 'MC-SPOOL',
            'machine_name' => 'Mesin Spool', 'location_name' => 'Lokasi Spool',
            'operator_name_snapshot' => 'Operator', 'approved_by_name_snapshot' => 'Admin',
            'submitted_at' => null, 'approved_at' => null, 'is_warning' => false,
            'has_deleted_execution' => false, 'schedule_only' => false,
        ]);
        $spool->writeDetail([
            'schedule_date_id' => 1, 'execution_id' => 2, 'execution_item_id' => 3,
            'part_name_snapshot' => 'Part Spool', 'standard_name_snapshot' => 'Standard Spool',
            'input_type_snapshot' => 'number', 'action_value' => null, 'number_value' => '42.00',
            'min_value_snapshot' => null, 'max_value_snapshot' => null, 'unit_snapshot' => 'C',
            'is_warning' => false, 'operator_note' => null,
        ]);
        $readyPath = $spool->finalize();
        $readySpool = ReportPmExportSpool::openReady($readyPath, 910001);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'pm_')) {
                $queries[] = $query->sql;
            }
        });

        Excel::store(new ReportPmWorkerExport($readySpool, app(ReportPmService::class)), 'consumer.xlsx', 'public');

        $this->assertSame([], $queries);
        $readySpool->delete();
        Storage::disk('public')->assertExists('consumer.xlsx');
    }

    public function test_invalid_export_shape_does_not_mutate_other_export_rows(): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false, withExecution: false);
        $sentinel = ReportExport::query()->create([
            'report_type' => 'breakdown',
            'file_type' => 'excel',
            'status' => 'processing',
            'filter_data' => [],
            'requested_by' => $fixture['admin']->id,
            'requested_by_name_snapshot' => $fixture['admin']->name,
            'requested_at' => now(),
        ]);

        try {
            app(ReportPmExportWorker::class)->generate((int) $sentinel->id);
            $this->fail('Non-PM export must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('processing', $sentinel->fresh()->status);
            $this->assertSame(1, ReportExport::query()->count());
            $this->assertSame([], Storage::disk('public')->allFiles());
        }

        $malformed = ReportExport::query()->create([
            'report_type' => 'pm',
            'file_type' => 'excel',
            'status' => 'processing',
            'filter_data' => ['scope' => []],
            'requested_by' => $fixture['admin']->id,
            'requested_by_name_snapshot' => $fixture['admin']->name,
            'requested_at' => now(),
        ]);
        try {
            app(ReportPmExportWorker::class)->generate((int) $malformed->id);
            $this->fail('Incomplete B1a filter_data must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('processing', $malformed->fresh()->status);
            $this->assertSame(2, ReportExport::query()->count());
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_retrieval_failure_marks_export_failed_and_removes_spool(): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'excel', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
        config(['report.pm_export_parent_page_size' => 0]);

        try {
            app(ReportPmExportWorker::class)->generate((int) $export->id);
            $this->fail('Invalid page size must fail retrieval.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('bilangan bulat positif', $exception->getMessage());
        }

        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertNotNull($export->failed_message);
        $this->assertSame([], glob(storage_path('framework/report-pm-spool/'.$export->id.'-*')) ?: []);
    }

    public function test_artifact_failure_does_not_mark_export_completed(): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'excel', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
        Excel::shouldReceive('store')->once()->andThrow(new RuntimeException('storage failed'));

        try {
            app(ReportPmExportWorker::class)->generate((int) $export->id);
            $this->fail('Artifact storage failure must be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('storage failed', $exception->getMessage());
        }

        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->file_path);
        $this->assertSame([], glob(storage_path('framework/report-pm-spool/'.$export->id.'-*')) ?: []);
    }

    public function test_excel_storage_false_fails_without_completed_metadata(): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'excel', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
        Excel::shouldReceive('store')->once()->andReturn(false);

        try {
            app(ReportPmExportWorker::class)->generate((int) $export->id);
            $this->fail('False storage result must fail the export.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gagal menyimpan artifact export PM.', $exception->getMessage());
        }

        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertSame('Gagal menyimpan artifact export PM.', $export->failed_message);
        $this->assertNull($export->completed_at);
        $this->assertNull($export->file_path);
        $this->assertNull($export->file_name);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], glob(storage_path('framework/report-pm-spool/'.$export->id.'-*')) ?: []);
    }

    public function test_pdf_storage_false_fails_without_completed_metadata(): void
    {
        $disk = Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'pdf', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
        $failedDisk = \Mockery::mock($disk)->makePartial();
        $failedDisk->shouldReceive('put')->once()->andReturn(false);
        Storage::set('public', $failedDisk);

        try {
            app(ReportPmExportWorker::class)->generate((int) $export->id);
            $this->fail('False PDF storage result must fail the export.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gagal menyimpan artifact export PM.', $exception->getMessage());
        }

        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertSame('Gagal menyimpan artifact export PM.', $export->failed_message);
        $this->assertNull($export->completed_at);
        $this->assertNull($export->file_path);
        $this->assertNull($export->file_name);
        $this->assertSame([], $disk->allFiles());
        $this->assertSame([], glob(storage_path('framework/report-pm-spool/'.$export->id.'-*')) ?: []);
    }

    public function test_spool_reader_rejects_trailing_record_after_footer(): void
    {
        $spool = ReportPmExportSpool::open(910002, ['report_export_id' => 910002]);
        $readyPath = $spool->finalize();
        file_put_contents($readyPath, "{\"type\":\"parent\"}\n", FILE_APPEND);
        $reader = ReportPmExportSpool::openReady($readyPath, 910002);

        try {
            foreach ($reader->records() as $record) {
                // Memaksa validasi footer dan urutan sampai akhir spool.
            }
            $this->fail('Records after the footer must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('record order', $exception->getMessage());
        } finally {
            $reader->delete();
        }
    }

    public function test_spool_reader_rejects_missing_footer(): void
    {
        $spool = ReportPmExportSpool::open(910003, ['report_export_id' => 910003]);
        $readyPath = $spool->finalize();
        $lines = file($readyPath, FILE_IGNORE_NEW_LINES);
        array_pop($lines);
        file_put_contents($readyPath, implode("\n", $lines)."\n");
        $reader = ReportPmExportSpool::openReady($readyPath, 910003);

        try {
            foreach ($reader->records() as $record) {
                // Memaksa validasi spool sampai EOF.
            }
            $this->fail('Spool tanpa footer harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('incomplete', $exception->getMessage());
        } finally {
            $reader->delete();
        }
    }

    #[DataProvider('invalidDetailIdentities')]
    public function test_spool_reader_rejects_checksum_valid_invalid_detail_identity(?int $parentExecutionId, int $scheduleDateId, int $executionId): void
    {
        $spool = ReportPmExportSpool::open(910004, ['report_export_id' => 910004]);
        $spool->writeParent(['schedule_date_id' => 1, 'execution_id' => $parentExecutionId]);
        $spool->writeDetail(['schedule_date_id' => $scheduleDateId, 'execution_id' => $executionId, 'execution_item_id' => 3]);
        $reader = ReportPmExportSpool::openReady($spool->finalize(), 910004);

        try {
            // Writer menghasilkan checksum sah; penolakan harus berasal dari identitas detail.
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('PM export spool detail identity does not match its parent.');
            iterator_to_array($reader->records(), false);
        } finally {
            $reader->delete();
        }
    }

    /** @return array<string, array{?int, int, int}> */
    public static function invalidDetailIdentities(): array
    {
        return [
            'other occurrence' => [2, 99, 2],
            'other execution' => [2, 1, 99],
            'schedule only' => [null, 1, 2],
        ];
    }

    #[DataProvider('unsupportedSnapshotEngines')]
    public function test_worker_rejects_unsupported_snapshot_table_before_retrieval(string $table, ?string $engine): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'excel', [
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]);
        $tables = ['pm_schedule_dates', 'pm_schedules', 'pm_executions', 'pm_execution_items', 'pm_execution_item_action_results', 'machines', 'locations'];
        $engines = [];
        foreach ($tables as $name) {
            if ($name !== $table || $engine !== null) {
                $engines[] = (object) ['table_name' => $name, 'engine' => $name === $table ? $engine : 'InnoDB'];
            }
        }
        $connection = \Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('select')->andReturn($engines);
        $connection->shouldReceive('selectOne')->andReturn((object) ['engine' => 'InnoDB', 'level' => 'REPEATABLE-READ']);
        $connection->shouldReceive('beginTransaction')->andThrow(new RuntimeException('PM retrieval must not begin.'));
        DB::shouldReceive('connection')->andReturn($connection);

        try {
            app(ReportPmExportWorker::class)->generate((int) $export->id);
            $this->fail('Unsupported snapshot table must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Tabel '.$table.' harus tersedia sebagai InnoDB untuk snapshot export PM.', $exception->getMessage());
        }

        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], glob(storage_path('framework/report-pm-spool/'.$export->id.'-*')) ?: []);
    }

    /** @return array<string, array{string, ?string}> */
    public static function unsupportedSnapshotEngines(): array
    {
        return [
            'nontransactional detail' => ['pm_execution_items', 'MyISAM'],
            'nontransactional identity' => ['machines', 'MyISAM'],
            'missing action table' => ['pm_execution_item_action_results', null],
        ];
    }

    public function test_default_october_registration_keeps_october_scope_when_worker_runs_in_november(): void
    {
        $this->travelTo('2026-10-06 12:00:00');
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'excel', []);
        $outside = PmScheduleDate::query()->create([
            'pm_schedule_id' => $fixture['schedule']->id, 'machine_id' => $fixture['machine']->id,
            'scheduled_date' => '2026-11-04', 'status' => 'approved',
        ]);
        $november = $fixture['execution']->replicate();
        $november->pm_schedule_date_id = $outside->id;
        $november->save();
        $november->items()->create([
            'part_name_snapshot' => 'November Only', 'standard_name_snapshot' => 'November Standard',
            'input_type_snapshot' => 'number', 'number_value' => 99, 'is_warning' => false,
        ]);
        $this->travelTo('2026-11-15 12:00:00');

        app(ReportPmExportWorker::class)->generate((int) $export->id);

        $export->refresh();
        $this->assertSame('2026-10-01', $export->filter_data['scope']['resolved_start_date']);
        $this->assertSame('2026-10-31', $export->filter_data['scope']['resolved_end_date']);
        $rows = IOFactory::load(Storage::disk('public')->path($export->file_path))->getActiveSheet()->toArray();
        $this->assertSame(array_fill(0, 7, '2026-10-04'), array_column(array_slice($rows, 1), 0));
        $this->assertNotContains('November Only', array_column($rows, 4));
    }

    public function test_worker_range_outputs_stored_actual_value_in_excel_and_pdf(): void
    {
        Storage::fake('public');
        $fixture = $this->createFixture(scheduleOnly: false);
        $fixture['execution']->items()->create([
            'part_name_snapshot' => 'Frozen Range Part', 'standard_name_snapshot' => 'Frozen Range Standard',
            'input_type_snapshot' => 'range', 'number_value' => 70.25,
            'min_value_snapshot' => 10, 'max_value_snapshot' => 80, 'unit_snapshot' => 'kPa', 'is_warning' => false,
        ]);
        $filters = ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'];
        $registration = app(ReportPmExportRegistrationService::class);
        $excel = $registration->register($fixture['admin'], 'excel', $filters);
        app(ReportPmExportWorker::class)->generate((int) $excel->id);
        $rows = IOFactory::load(Storage::disk('public')->path($excel->fresh()->file_path))->getActiveSheet()->toArray();
        $range = collect(array_slice($rows, 1))->first(fn (array $row): bool => $row[4] === 'Frozen Range Part');
        $this->assertSame('Frozen Range Standard', $range[5]);
        $this->assertSame('RANGE', $range[6]);
        $this->assertSame('70.25', $range[8]);
        $this->assertSame('kPa', $range[9]);

        $pdf = $registration->register($fixture['admin'], 'pdf', $filters);
        $rendered = null;
        $renderer = \Mockery::mock(DomPdf::class)->makePartial();
        $renderer->shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use (&$rendered): bool {
            $rendered = (string) view($view, $data);

            return true;
        })->andReturnSelf();
        $renderer->shouldReceive('setPaper')->once()->andReturnSelf();
        $renderer->shouldReceive('output')->once()->andReturn('%PDF-1.4 range');
        app()->instance('dompdf.wrapper', $renderer);
        app(ReportPmExportWorker::class)->generate((int) $pdf->id);
        $this->assertMatchesRegularExpression('/Frozen Range Standard<\/td>\s*<td>RANGE<\/td>\s*<td>-<\/td>\s*<td>70\.25<\/td>\s*<td>kPa<\/td>/', $rendered);
    }

    public function test_parent_continuation_preserves_equal_date_machine_and_id_order_without_skips(): void
    {
        Storage::fake('public');
        config(['report.pm_export_parent_page_size' => 1]);
        $fixture = $this->createFixture(scheduleOnly: false, withExecution: false);
        $secondMachine = $fixture['machine']->replicate();
        $secondMachine->machine_code = 'ZZ-WORKER';
        $secondMachine->qr_token = 'qr-second-worker';
        $secondMachine->save();
        $secondSchedule = $fixture['schedule']->replicate();
        $secondSchedule->lifecycle_status = 'ended';
        $secondSchedule->save();
        $secondAssignment = PmChecksheetMachine::query()->where('machine_id', $fixture['machine']->id)->firstOrFail()->replicate();
        $secondAssignment->machine_id = $secondMachine->id;
        $secondAssignment->save();
        $machineSchedule = $fixture['schedule']->replicate();
        $machineSchedule->pm_checksheet_machine_id = $secondAssignment->id;
        $machineSchedule->save();
        $create = fn (int $machineId, int $scheduleId, string $date, string $status) => PmScheduleDate::query()->create([
            'machine_id' => $machineId, 'pm_schedule_id' => $scheduleId,
            'scheduled_date' => $date, 'status' => $status,
        ]);
        $tie = $create($fixture['machine']->id, $secondSchedule->id, '2026-10-04', 'approved');
        $otherMachine = $create($secondMachine->id, $machineSchedule->id, '2026-10-04', 'approved');
        $older = $create($fixture['machine']->id, $fixture['schedule']->id, '2026-10-02', 'scheduled');
        $attention = $create($secondMachine->id, $machineSchedule->id, '2026-10-01', 'overdue');
        $export = app(ReportPmExportRegistrationService::class)->register($fixture['admin'], 'pdf', [
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]);
        $captured = null;
        $renderer = \Mockery::mock(DomPdf::class)->makePartial();
        $renderer->shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use (&$captured): bool {
            $captured = $data['parentGroups'];

            return true;
        })->andReturnSelf();
        $renderer->shouldReceive('setPaper')->once()->andReturnSelf();
        $renderer->shouldReceive('output')->once()->andReturn('%PDF-1.4 continuation');
        app()->instance('dompdf.wrapper', $renderer);

        app(ReportPmExportWorker::class)->generate((int) $export->id);

        $this->assertSame([
            $attention->id, $tie->id, $fixture['occurrence']->id, $otherMachine->id, $older->id,
        ], $captured->pluck('schedule_date_id')->all());
    }

    /**
     * @return array{admin:User, machine:Machine, occurrence:PmScheduleDate, execution:?PmExecution, schedule:PmSchedule}
     */
    private function createFixture(bool $scheduleOnly = true, bool $withExecution = true): array
    {
        $location = Location::query()->create([
            'location_code' => 'LOC-WORKER',
            'location_name' => 'Lokasi Worker',
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'operator']);
        $machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-WORKER',
            'machine_name' => 'Mesin Worker',
            'qr_token' => 'qr-worker',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'CHK-WORKER',
            'checksheet_name' => 'Checksheet Worker',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $assignment = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $machine->id,
            'created_by' => $admin->id,
        ]);
        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $assignment->id,
            'frequency_type' => 'daily',
            'start_date' => '2026-10-01',
            'generate_until' => '2026-10-31',
            'is_active' => true,
            'lifecycle_status' => 'active',
            'created_by' => $admin->id,
        ]);
        $occurrence = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => '2026-10-04',
            'status' => 'approved',
            'status_changed_at' => now(),
        ]);
        $execution = null;
        if ($withExecution) {
            $execution = PmExecution::query()->create([
                'pm_schedule_date_id' => $occurrence->id,
                'machine_id' => $machine->id,
                'operator_id' => $operator->id,
                'operator_name_snapshot' => $operator->name,
                'machine_code_snapshot' => $machine->machine_code,
                'machine_name_snapshot' => $machine->machine_name,
                'location_code_snapshot' => $location->location_code,
                'location_name_snapshot' => $location->location_name,
                'status' => 'approved',
                'started_at' => '2026-10-04 08:00:00',
                'submitted_at' => '2026-10-04 09:00:00',
                'approved_at' => '2026-10-04 10:00:00',
                'approved_by' => $admin->id,
                'approved_by_name_snapshot' => $admin->name,
            ]);
            $multi = $execution->items()->create([
                'part_name_snapshot' => 'Part Multi',
                'standard_name_snapshot' => 'Standard Multi',
                'input_type_snapshot' => 'action',
                'action_value' => 'legacy',
                'is_warning' => false,
            ]);
            foreach (['Lubricate' => 2, 'Inspect' => 1] as $label => $position) {
                $multi->actionResults()->create([
                    'selected_label_snapshot' => $label,
                    'selected_label_identity_hash' => hash('sha256', $label),
                    'option_position_snapshot' => $position,
                ]);
            }
            foreach (['Part Number A', 'Part Number B', 'Part Number C', 'Part Number D', 'Part Number E', 'Part Number F'] as $partName) {
                $execution->items()->create([
                    'part_name_snapshot' => $partName,
                    'standard_name_snapshot' => 'Standard '.$partName,
                    'input_type_snapshot' => 'number',
                    'number_value' => 42,
                    'is_warning' => false,
                ]);
            }
        }

        if ($scheduleOnly) {
            PmScheduleDate::query()->create([
                'pm_schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'scheduled_date' => '2026-10-03',
                'status' => 'scheduled',
                'status_changed_at' => now(),
            ]);
        }

        return compact('admin', 'machine', 'occurrence', 'execution', 'schedule');
    }
}
