<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\Report\ReportPmExportQuery;
use App\Services\Report\ReportPmExportSpool;
use App\Services\Report\ReportPmExportWorker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const B2_TEST_DATABASE = 'db_preventive_maintenance_ai_test';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$defaultConnection = (string) config('database.default');
$baseConfig = config('database.connections.'.$defaultConnection);
if (! is_array($baseConfig) || ($baseConfig['driver'] ?? null) !== 'mysql') {
    throw new RuntimeException('STOP: aplikasi tidak memiliki koneksi MySQL yang dapat dipakai untuk proof.');
}

foreach (['a', 'b'] as $suffix) {
    $connectionName = 'b2_pm_snapshot_'.$suffix;
    $connectionConfig = $baseConfig;
    $connectionConfig['database'] = B2_TEST_DATABASE;
    config(['database.connections.'.$connectionName => $connectionConfig]);
    DB::purge($connectionName);
}

$connectionA = DB::connection('b2_pm_snapshot_a');
$connectionB = DB::connection('b2_pm_snapshot_b');
$identity = (string) ($connectionA->selectOne('SELECT DATABASE() AS database_name')->database_name ?? '');
if ($identity !== B2_TEST_DATABASE) {
    throw new RuntimeException('STOP: target database mismatch: '.$identity);
}

echo 'database='.$identity.PHP_EOL;

$requiredTables = ReportPmExportWorker::SNAPSHOT_TABLES;
$placeholders = implode(', ', array_fill(0, count($requiredTables), '?'));
$engines = $connectionA->select(
    'SELECT LOWER(TABLE_NAME) AS table_name, LOWER(ENGINE) AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('.$placeholders.')',
    $requiredTables,
);
$engineMap = [];
foreach ($engines as $engine) {
    $engineMap[(string) $engine->table_name] = strtolower((string) $engine->engine);
}
foreach ($requiredTables as $table) {
    if (($engineMap[$table] ?? null) !== 'innodb') {
        throw new RuntimeException('STOP: '.$table.' tidak tersedia sebagai InnoDB pada database test.');
    }
}
config(['database.default' => 'b2_pm_snapshot_a']);

$token = 'B2P'.date('ymdHis').bin2hex(random_bytes(3));
$ledger = [];
$spool = null;
$aStarted = false;
$proofPassed = false;

try {
    $ledger['location'] = Location::query()->create([
        'location_code' => $token.'-L', 'location_name' => $token.' Location', 'is_active' => true,
    ])->id;
    $ledger['admin'] = User::query()->create([
        'name' => $token.' Admin', 'username' => $token.'-admin', 'password' => 'proof-password',
        'role' => 'admin', 'is_active' => true,
    ])->id;
    $ledger['operator'] = User::query()->create([
        'name' => $token.' Operator', 'username' => $token.'-operator', 'password' => 'proof-password',
        'role' => 'operator', 'is_active' => true,
    ])->id;
    $ledger['machine'] = Machine::query()->create([
        'location_id' => $ledger['location'], 'machine_code' => $token.'-M',
        'machine_name' => $token.' Machine', 'qr_token' => $token.'-QR',
        'is_active' => true, 'lifecycle_status' => 'active',
    ])->id;
    $ledger['checksheet'] = PmChecksheet::query()->create([
        'checksheet_code' => $token.'-C', 'checksheet_name' => $token.' Checksheet',
        'is_active' => true, 'created_by' => $ledger['admin'],
    ])->id;
    $ledger['assignment'] = PmChecksheetMachine::query()->create([
        'pm_checksheet_id' => $ledger['checksheet'], 'machine_id' => $ledger['machine'],
        'created_by' => $ledger['admin'],
    ])->id;
    $ledger['schedule'] = PmSchedule::query()->create([
        'pm_checksheet_machine_id' => $ledger['assignment'], 'frequency_type' => 'daily',
        'start_date' => '2026-10-01', 'generate_until' => '2026-10-31', 'is_active' => true,
        'lifecycle_status' => 'active', 'created_by' => $ledger['admin'],
    ])->id;
    $ledger['earlier_parent'] = PmScheduleDate::query()->create([
        'pm_schedule_id' => $ledger['schedule'], 'machine_id' => $ledger['machine'],
        'scheduled_date' => '2026-10-07', 'status' => 'approved',
    ])->id;
    $ledger['later_parent'] = PmScheduleDate::query()->create([
        'pm_schedule_id' => $ledger['schedule'], 'machine_id' => $ledger['machine'],
        'scheduled_date' => '2026-10-08', 'status' => 'approved',
    ])->id;
    $ledger['execution'] = PmExecution::query()->create([
        'pm_schedule_date_id' => $ledger['later_parent'], 'machine_id' => $ledger['machine'],
        'operator_id' => $ledger['operator'], 'operator_name_snapshot' => $token.' Operator',
        'machine_code_snapshot' => $token.'-M', 'machine_name_snapshot' => $token.' Machine',
        'location_code_snapshot' => $token.'-L', 'location_name_snapshot' => $token.' Location',
        'status' => 'approved', 'started_at' => '2026-10-08 08:00:00',
        'submitted_at' => '2026-10-08 09:00:00',
    ])->id;
    $ledger['item'] = DB::table('pm_execution_items')->insertGetId([
        'pm_execution_id' => $ledger['execution'], 'part_name_snapshot' => $token.' Part',
        'standard_name_snapshot' => $token.' Standard', 'input_type_snapshot' => 'action',
        'action_value' => 'legacy', 'is_warning' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $ledger['action'] = DB::table('pm_execution_item_action_results')->insertGetId([
        'pm_execution_item_id' => $ledger['item'], 'selected_label_snapshot' => 'Snapshot Before',
        'selected_label_identity_hash' => hash('sha256', 'Snapshot Before'),
        'option_position_snapshot' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Proof memakai gate worker yang sama, bukan asumsi engine/isolation terpisah.
    $snapshotGate = new ReflectionMethod(ReportPmExportWorker::class, 'prepareConsistentSnapshot');
    $snapshotGate->invoke(app(ReportPmExportWorker::class), $connectionA);
    $isolation = strtoupper(str_replace('-', ' ', (string) ($connectionA->selectOne('SELECT @@transaction_isolation AS level')->level ?? '')));
    if ($isolation !== 'REPEATABLE READ') {
        throw new RuntimeException('STOP: Worker A did not establish REPEATABLE READ.');
    }
    $connectionA->beginTransaction();
    $aStarted = true;

    config(['database.default' => 'b2_pm_snapshot_a']);
    $filters = array_fill_keys([
        'start_date', 'end_date', 'location_id', 'machine_id', 'part_name', 'pic_operator', 'pic_admin', 'status', 'warning', 'search',
    ], null);
    $filters['start_date'] = '2026-10-01';
    $filters['end_date'] = '2026-10-31';
    $filters['machine_id'] = (int) $ledger['machine'];
    $scope = [
        'period_mode' => 'explicit', 'resolved_start_date' => '2026-10-01',
        'resolved_end_date' => '2026-10-31', 'include_attention_backlog' => false,
    ];
    $query = app(ReportPmExportQuery::class);
    $firstPage = $query->parentPage($filters, $scope, null, 1);
    if (count($firstPage) !== 1 || (int) $firstPage[0]->schedule_date_id !== $ledger['later_parent']) {
        throw new RuntimeException('Worker A initial page did not select the expected later parent.');
    }

    $spool = ReportPmExportSpool::open(990001, ['report_export_id' => 990001, 'proof_token' => $token]);
    $firstParent = $firstPage[0];
    $spool->writeParent((array) $firstParent);

    $connectionB->beginTransaction();
    try {
        $connectionB->table('pm_schedule_dates')->where('id', $ledger['earlier_parent'])->update(['status' => 'overdue']);
        $connectionB->table('pm_execution_item_action_results')->where('id', $ledger['action'])
            ->update(['selected_label_snapshot' => 'Changed After Snapshot']);
        $connectionB->commit();
    } catch (Throwable $exception) {
        $connectionB->rollBack();

        throw $exception;
    }

    $detailPage = $query->detailPage((int) $firstParent->execution_id, null, 10);
    $actionPage = $query->actionLabelsFor([(int) $ledger['item']], null, 10);
    if (count($detailPage) !== 1 || count($actionPage) !== 1
        || $actionPage[0]->selected_label_snapshot !== 'Snapshot Before') {
        throw new RuntimeException('Worker A did not retain pre-commit Action-result data.');
    }
    $detail = (array) $detailPage[0];
    $detail['schedule_date_id'] = (int) $firstParent->schedule_date_id;
    $detail['execution_id'] = (int) $firstParent->execution_id;
    $detail['multiple_action_labels'] = $actionPage[0]->selected_label_snapshot;
    $spool->writeDetail($detail);
    $spool->finishParent();

    $continuation = [
        'status_bucket' => (int) $firstParent->status_bucket,
        'scheduled_date' => (string) $firstParent->scheduled_date,
        'machine_code_sort' => (string) $firstParent->machine_code_sort,
        'schedule_date_id' => (int) $firstParent->schedule_date_id,
    ];
    $nextPage = $query->parentPage($filters, $scope, $continuation, 1);
    if (count($nextPage) !== 1 || (int) $nextPage[0]->schedule_date_id !== $ledger['earlier_parent']
        || $nextPage[0]->schedule_status !== 'approved') {
        throw new RuntimeException('Worker A continuation did not retain the pre-commit parent order/status.');
    }
    $spool->writeParent((array) $nextPage[0]);
    $spool->finishParent();
    $readyPath = $spool->finalize();
    $connectionA->commit();
    $aStarted = false;

    $records = iterator_to_array(ReportPmExportSpool::openReady($readyPath, 990001)->records(), false);
    $parentIds = array_values(array_map(
        static fn (array $record): int => (int) $record['schedule_date_id'],
        array_filter($records, static fn (array $record): bool => $record['type'] === 'parent'),
    ));
    $detailRecord = array_values(array_filter($records, static fn (array $record): bool => $record['type'] === 'detail'))[0] ?? null;
    if ($parentIds !== [$ledger['later_parent'], $ledger['earlier_parent']]
        || ($detailRecord['multiple_action_labels'] ?? null) !== 'Snapshot Before') {
        throw new RuntimeException('Final spool has duplicate, missing, reordered, or post-snapshot data.');
    }

    $proofPassed = true;
} finally {
    if ($aStarted) {
        $connectionA->rollBack();
    }
    config(['database.default' => $defaultConnection]);
    if ($spool instanceof ReportPmExportSpool) {
        try {
            $spool->delete();
        } catch (Throwable) {
            // Cleanup failure is reported below by checking owned rows.
        }
    }
    cleanupOwnedRows($ledger);
}

if ($proofPassed) {
    echo "B2 PM worker snapshot proof PASS\n";
    echo "isolation=REPEATABLE READ first_parent=stable continuation=stable action_label=Snapshot Before\n";
    echo "worker_b_committed_status=overdue worker_b_committed_action=Changed After Snapshot\n";
    echo "cleanup=owned fixtures only\n";
}

function cleanupOwnedRows(array $ledger): void
{
    if ($ledger === []) {
        return;
    }

    if (isset($ledger['item'])) {
        DB::connection('b2_pm_snapshot_b')->table('pm_execution_item_action_results')
            ->where('pm_execution_item_id', $ledger['item'])->delete();
        DB::connection('b2_pm_snapshot_b')->table('pm_execution_items')->where('id', $ledger['item'])->delete();
    }
    if (isset($ledger['execution'])) {
        DB::connection('b2_pm_snapshot_b')->table('pm_executions')->where('id', $ledger['execution'])->delete();
    }
    if (isset($ledger['later_parent'], $ledger['earlier_parent'])) {
        DB::connection('b2_pm_snapshot_b')->table('pm_schedule_dates')
            ->whereIn('id', [$ledger['later_parent'], $ledger['earlier_parent']])->delete();
    }
    foreach (['schedule' => 'pm_schedules', 'assignment' => 'pm_checksheet_machines', 'checksheet' => 'pm_checksheets', 'machine' => 'machines', 'location' => 'locations', 'operator' => 'users', 'admin' => 'users'] as $key => $table) {
        if (isset($ledger[$key])) {
            DB::connection('b2_pm_snapshot_b')->table($table)->where('id', $ledger[$key])->delete();
        }
    }
    $ownedRows = [
        'pm_execution_item_action_results' => [$ledger['action'] ?? null],
        'pm_execution_items' => [$ledger['item'] ?? null],
        'pm_executions' => [$ledger['execution'] ?? null],
        'pm_schedule_dates' => [$ledger['later_parent'] ?? null, $ledger['earlier_parent'] ?? null],
        'pm_schedules' => [$ledger['schedule'] ?? null],
        'pm_checksheet_machines' => [$ledger['assignment'] ?? null],
        'pm_checksheets' => [$ledger['checksheet'] ?? null],
        'machines' => [$ledger['machine'] ?? null],
        'locations' => [$ledger['location'] ?? null],
        'users' => [$ledger['operator'] ?? null, $ledger['admin'] ?? null],
    ];
    foreach ($ownedRows as $table => $ids) {
        foreach (array_filter($ids) as $id) {
            if (DB::connection('b2_pm_snapshot_b')->table($table)->where('id', $id)->exists()) {
                throw new RuntimeException('Owned row cleanup failed: '.$table.'#'.$id);
            }
        }
    }
}
