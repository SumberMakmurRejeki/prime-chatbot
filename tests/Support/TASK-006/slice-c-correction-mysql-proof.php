<?php

/**
 * TASK-006 Slice C (koreksi B-5): bukti MySQL terikat untuk memastikan
 * Machine dengan era Schedule ENDED tidak dapat menghapus histori melalui
 * cascade. Hanya fixture milik skrip ini yang ditulis dan dibersihkan.
 */

declare(strict_types=1);

// Autoload Laravel agar application service dipanggil pada koneksi test.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Models\Machine;
use App\Services\Master\MachineService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

const TARGET_DATABASE = 'db_preventive_maintenance_ai_test';

$failures = [];
$proofs = 0;

function prove(string $label, bool $passed, string $detail = ''): void
{
    global $failures, $proofs;
    $proofs++;
    if (! $passed) {
        $failures[] = $label.($detail === '' ? '' : " ({$detail})");
    }
    printf("[%s] %s%s\n", $passed ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : " — {$detail}");
}

function envValue(string $key, string $default = ''): string
{
    $path = dirname(__DIR__, 3).'/.env';
    if (! is_file($path)) {
        return $default;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), $key.'=')) {
            return trim(trim(substr(trim($line), strlen($key) + 1)), "\"'");
        }
    }
    return $default;
}

function ownInsert(PDO $pdo, string $sql, array &$owned, string $table): int
{
    $pdo->exec($sql);
    $id = (int) $pdo->lastInsertId();
    $owned[$table][] = $id;
    return $id;
}

$pdo = new PDO(
    'mysql:host='.envValue('DB_HOST', '127.0.0.1').';port='.envValue('DB_PORT', '3306').';dbname='.TARGET_DATABASE,
    envValue('DB_USERNAME', 'root'),
    envValue('DB_PASSWORD', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($actualDatabase !== TARGET_DATABASE || in_array($actualDatabase, ['db_preventive_maintenance', 'db_preventive_maintenance_test'], true)) {
    fwrite(STDERR, "STOP — UNSAFE MYSQL DATABASE TARGET (SELECT DATABASE() = {$actualDatabase})\n");
    exit(2);
}
prove('database identity guard', true, 'SELECT DATABASE() = '.$actualDatabase);

$owned = array_fill_keys(['locations', 'machines', 'pm_checksheets', 'pm_checksheet_machines', 'pm_schedules', 'pm_schedule_dates', 'pm_executions', 'user_activity_logs'], []);
$baseline = [];
$cleanupFailures = [];
$fatal = null;
$suffix = bin2hex(random_bytes(4));

try {
    foreach (array_keys($owned) as $table) {
        $baseline[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    // Bootstrap Laravel dan ganti koneksi application ke database test yang sama.
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => TARGET_DATABASE]);
    DB::purge('mysql');
    DB::reconnect('mysql');
    $laravelDatabase = (string) DB::connection('mysql')->getDatabaseName();
    if ($laravelDatabase !== TARGET_DATABASE) {
        throw new RuntimeException("STOP — Laravel connection resolved {$laravelDatabase}, not ".TARGET_DATABASE);
    }
    prove('Laravel application connection uses test database', true, $laravelDatabase);

    // ActivityLogService membutuhkan request dengan session untuk delete sukses.
    $request = Request::create('/proof/task-006', 'DELETE');
    $request->setLaravelSession($app->make('session.store'));
    $app->instance('request', $request);
    $service = $app->make(MachineService::class);

    $makeMachine = function (string $tag, string $lifecycle, bool $active) use ($pdo, $suffix, &$owned): int {
        $location = ownInsert($pdo,
            'INSERT INTO locations (location_code, location_name, is_active, created_at, updated_at) VALUES ('
            .$pdo->quote("PROOF-LOC-{$suffix}-{$tag}").", 'Slice C Proof Location', 0, NOW(), NOW())",
            $owned, 'locations');
        return ownInsert($pdo,
            "INSERT INTO machines (location_id, machine_code, machine_name, qr_token, is_active, lifecycle_status, created_at, updated_at) VALUES ({$location}, "
            .$pdo->quote("PROOF-MC-{$suffix}-{$tag}").", 'Slice C Proof Machine', ".$pdo->quote("proof-qr-{$suffix}-{$tag}").', '.($active ? '1' : '0').", '{$lifecycle}', NOW(), NOW())",
            $owned, 'machines');
    };

    $makeSchedule = function (int $machine, string $tag, string $lifecycle, bool $active) use ($pdo, $suffix, &$owned): int {
        $checksheet = ownInsert($pdo,
            'INSERT INTO pm_checksheets (checksheet_code, checksheet_name, is_active, created_at, updated_at) VALUES ('
            .$pdo->quote("PROOF-CH-{$suffix}-{$tag}").", 'Slice C Proof Checksheet', 0, NOW(), NOW())",
            $owned, 'pm_checksheets');
        $assignment = ownInsert($pdo,
            "INSERT INTO pm_checksheet_machines (pm_checksheet_id, machine_id, assigned_at, created_at, updated_at) VALUES ({$checksheet}, {$machine}, NOW(), NOW(), NOW())",
            $owned, 'pm_checksheet_machines');
        return ownInsert($pdo,
            "INSERT INTO pm_schedules (pm_checksheet_machine_id, frequency_type, operational_from, start_date, generate_until, is_active, lifecycle_status, created_at, updated_at) VALUES ({$assignment}, 'daily', '2026-09-01', '2026-09-01', '2026-10-01', ".($active ? '1' : '0').", '{$lifecycle}', NOW(), NOW())",
            $owned, 'pm_schedules');
    };

    // B-5: ENDED tanpa occurrence harus ditolak sebelum cascade FK.
    $endedMachine = $makeMachine('ENDED', 'retired', false);
    $endedSchedule = $makeSchedule($endedMachine, 'ENDED', 'ended', false);
    $endedResult = $service->delete($request, Machine::query()->findOrFail($endedMachine));
    prove('ENDED schedule blocks Machine delete before cascade', $endedResult === false, 'result='.var_export($endedResult, true));
    prove('ENDED fixture remains unchanged',
        (int) $pdo->query("SELECT COUNT(*) FROM machines WHERE id = {$endedMachine}")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE id = {$endedSchedule} AND lifecycle_status = 'ended'")->fetchColumn() === 1);
    // Operasi yang ditolak tidak boleh menulis activity log delete sukses.
    prove('rejected ENDED delete creates no activity log', (int) $pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE module_name = 'master_mesin' AND action = 'delete' AND table_name = 'machines' AND record_id = {$endedMachine}")->fetchColumn() === 0);

    // Soft-deleted ENDED adalah histori dan harus memiliki proteksi sama.
    $softMachine = $makeMachine('SOFT', 'retired', false);
    $softSchedule = $makeSchedule($softMachine, 'SOFT', 'ended', false);
    $pdo->exec("UPDATE pm_schedules SET deleted_at = NOW() WHERE id = {$softSchedule}");
    $softResult = $service->delete($request, Machine::query()->findOrFail($softMachine));
    prove('soft-deleted ENDED schedule blocks Machine delete', $softResult === false, 'result='.var_export($softResult, true));
    prove('soft-deleted ENDED row remains', (int) $pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE id = {$softSchedule} AND deleted_at IS NOT NULL AND lifecycle_status = 'ended'")->fetchColumn() === 1);
    prove('rejected soft-deleted ENDED delete creates no activity log', (int) $pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE module_name = 'master_mesin' AND action = 'delete' AND table_name = 'machines' AND record_id = {$softMachine}")->fetchColumn() === 0);

    // Kontrol: Machine tanpa assignment/Schedule tetap dapat dihapus authority.
    $unusedMachine = $makeMachine('UNUSED', 'active', true);
    $unusedMachineCode = "PROOF-MC-{$suffix}-UNUSED";
    $unusedResult = $service->delete($request, Machine::query()->findOrFail($unusedMachine));
    prove('unused Machine remains deletable', $unusedResult === true, 'result='.var_export($unusedResult, true));
    prove('unused Machine row disappears', (int) $pdo->query("SELECT COUNT(*) FROM machines WHERE id = {$unusedMachine}")->fetchColumn() === 0);
    // Jalur delete sukses wajib menulis TEPAT satu activity log canonical milik fixture ini.
    $unusedLogs = $pdo->query("SELECT id, module_name, action, table_name, record_id, description FROM user_activity_logs WHERE module_name = 'master_mesin' AND action = 'delete' AND table_name = 'machines' AND record_id = {$unusedMachine} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    prove('unused Machine delete writes exactly one activity log', count($unusedLogs) === 1, 'count='.count($unusedLogs));
    $unusedLog = $unusedLogs[0] ?? null;
    prove('unused Machine delete activity log is canonical', is_array($unusedLog)
        && (int) $unusedLog['record_id'] === $unusedMachine
        && $unusedLog['module_name'] === 'master_mesin'
        && $unusedLog['action'] === 'delete'
        && $unusedLog['table_name'] === 'machines'
        && str_contains((string) $unusedLog['description'], $unusedMachineCode), 'log='.var_export($unusedLog, true));
    foreach ($unusedLogs as $ownedUnusedLog) {
        $owned['user_activity_logs'][] = (int) $ownedUnusedLog['id'];
    }

    // Boundary TASK-003: ACTIVE + occurrence mutable tanpa execution masih deletable.
    $activeMachine = $makeMachine('ACTIVE', 'active', true);
    $activeMachineCode = "PROOF-MC-{$suffix}-ACTIVE";
    $activeSchedule = $makeSchedule($activeMachine, 'ACTIVE', 'active', true);
    $activeDate = ownInsert($pdo,
        "INSERT INTO pm_schedule_dates (pm_schedule_id, machine_id, scheduled_date, status, created_at, updated_at) VALUES ({$activeSchedule}, {$activeMachine}, '2026-09-20', 'scheduled', NOW(), NOW())",
        $owned, 'pm_schedule_dates');
    $activeResult = $service->delete($request, Machine::query()->findOrFail($activeMachine));
    prove('ACTIVE mutable configuration remains deletable', $activeResult === true, 'result='.var_export($activeResult, true));
    prove('ACTIVE configuration cascade completed',
        (int) $pdo->query("SELECT COUNT(*) FROM machines WHERE id = {$activeMachine}")->fetchColumn() === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE id = {$activeSchedule}")->fetchColumn() === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM pm_schedule_dates WHERE id = {$activeDate}")->fetchColumn() === 0);
    $activeLogs = $pdo->query("SELECT id, module_name, action, table_name, record_id, description FROM user_activity_logs WHERE module_name = 'master_mesin' AND action = 'delete' AND table_name = 'machines' AND record_id = {$activeMachine} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    prove('ACTIVE Machine delete writes exactly one activity log', count($activeLogs) === 1, 'count='.count($activeLogs));
    $activeLog = $activeLogs[0] ?? null;
    prove('ACTIVE Machine delete activity log is canonical', is_array($activeLog)
        && (int) $activeLog['record_id'] === $activeMachine
        && $activeLog['module_name'] === 'master_mesin'
        && $activeLog['action'] === 'delete'
        && $activeLog['table_name'] === 'machines'
        && str_contains((string) $activeLog['description'], $activeMachineCode), 'log='.var_export($activeLog, true));
    foreach ($activeLogs as $ownedActiveLog) {
        $owned['user_activity_logs'][] = (int) $ownedActiveLog['id'];
    }
} catch (Throwable $exception) {
    $fatal = $exception;
} finally {
    // Cleanup reverse-FK; tiap langkah tetap dicoba saat setup parsial gagal.
    // user_activity_logs diletakkan terakhir sesuai urutan plan; tabel ini tidak punya
    // FK ke machines, sehingga urutan penghapusannya tidak memengaruhi integritas.
    foreach (['pm_executions', 'pm_schedule_dates', 'pm_schedules', 'pm_checksheet_machines', 'pm_checksheets', 'machines', 'locations', 'user_activity_logs'] as $table) {
        try {
            if ($owned[$table] !== []) {
                $pdo->exec("DELETE FROM {$table} WHERE id IN (".implode(',', $owned[$table]).')');
            }
        } catch (Throwable $exception) {
            $cleanupFailures[] = "{$table}: ".$exception->getMessage();
        }
    }
    try {
        if ($owned['machines'] !== []) {
            $pdo->exec("DELETE FROM user_activity_logs WHERE module_name = 'master_mesin' AND action = 'delete' AND table_name = 'machines' AND record_id IN (".implode(',', $owned['machines']).')');
        }
    } catch (Throwable $exception) {
        $cleanupFailures[] = 'user_activity_logs: '.$exception->getMessage();
    }
    try {
        // Residual eksplisit: activity log milik fixture Machine tidak boleh tersisa.
        $ownedMachineIds = $owned['machines'] === [] ? 'NULL' : implode(',', $owned['machines']);
        $remainingActivityLogs = (int) $pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE module_name = 'master_mesin' AND action = 'delete' AND table_name = 'machines' AND record_id IN ({$ownedMachineIds})")->fetchColumn();
        prove('owned Machine activity-log residual is zero', $remainingActivityLogs === 0, "remaining={$remainingActivityLogs}");
    } catch (Throwable $exception) {
        $cleanupFailures[] = 'user_activity_logs residual: '.$exception->getMessage();
    }
    try {
        foreach ($owned as $table => $tableIds) {
            // Semua tabel rencana diverifikasi; NULL membuat IN() tetap aman
            // saat skenario memang tidak membuat fixture untuk tabel tersebut.
            $ownedIds = $tableIds === [] ? 'NULL' : implode(',', $tableIds);
            $remaining = (int) $pdo->query(
                "SELECT COUNT(*) FROM {$table} WHERE id IN ({$ownedIds})"
            )->fetchColumn();
            prove("owned fixture ids absent from {$table}", $remaining === 0, "remaining={$remaining}");
        }

        foreach ($baseline as $table => $count) {
            $actual = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            prove("control count restored for {$table}", $actual === $count, "baseline={$count} actual={$actual}");
        }
    } catch (Throwable $exception) {
        $cleanupFailures[] = 'residual verification: '.$exception->getMessage();
    }
    prove('owned fixture cleanup executed without failure', $cleanupFailures === [], $cleanupFailures === [] ? 'cleanup bersih' : implode('; ', $cleanupFailures));
}

printf("\n%d pembuktian dijalankan; %d gagal.\n", $proofs, count($failures));
if ($fatal !== null) {
    fwrite(STDERR, 'PROOF ABORTED: '.$fatal->getMessage()."\n");
    exit(1);
}
if ($failures !== []) {
    fwrite(STDERR, "FAILED PROOFS:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}
echo "SLICE C CORRECTION MYSQL PROOF — PASS\n";
