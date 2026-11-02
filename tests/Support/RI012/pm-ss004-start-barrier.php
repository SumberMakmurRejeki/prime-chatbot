<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmAction;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetPart;
use App\Models\PmChecksheetStandard;
use App\Models\PmExecution;
use App\Models\PmExecutionItem;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\Master\PmActionService;
use App\Services\PM\PmExecutionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const HARNESS_DATABASE = 'db_preventive_maintenance_ai_test';
const HARNESS_TIMEOUT = 45;

$mode = $argv[1] ?? 'orchestrate';
$scenario = $argv[2] ?? 'rename-between';

if ($mode === 'cleanup') {
    bootstrapApplication();
    assertSafeConnection();
    cleanupByToken($argv[2] ?? '');
    exit(0);
}

if ($mode === 'worker') {
    try {
        runWorker($argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? '', $argv[2] ?? '', $argv[6] ?? 'a');
        exit(0);
    } catch (Throwable $exception) {
        $directory = $argv[4] ?? '';
        if ($directory !== '') {
            writeJson($directory.'/worker.error.json', [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
        fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
        exit(1);
    }
}

try {
    runCoordinator($scenario);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    echo "HARNESS FAILED\n";
    exit(1);
}

function bootstrapApplication(): void
{
    foreach ([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => HARNESS_DATABASE,
        'DB_URL' => '',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => HARNESS_DATABASE]);
    DB::purge('mysql');
    DB::reconnect('mysql');
}

/** @return array{database:string,isolation:string,version:string,engines:array<int,string>} */
function assertSafeConnection(): array
{
    $connection = DB::connection('mysql');
    $identity = $connection->selectOne('SELECT DATABASE() AS database_name, @@transaction_isolation AS transaction_isolation, VERSION() AS mysql_version');
    $engines = array_values(array_unique(array_map(
        static fn (object $row): string => (string) $row->engine,
        $connection->select(
            'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?) ORDER BY TABLE_NAME',
            ['pm_actions', 'pm_checksheet_standard_actions', 'pm_executions', 'pm_execution_items'],
        ),
    )));

    if ($identity->database_name !== HARNESS_DATABASE || $engines !== ['InnoDB']) {
        throw new RuntimeException('Unsafe MySQL target or table engine.');
    }

    return [
        'database' => (string) $identity->database_name,
        'isolation' => (string) $identity->transaction_isolation,
        'version' => (string) $identity->mysql_version,
        'engines' => $engines,
    ];
}

function runCoordinator(string $scenario): void
{
    if ($scenario !== 'barrier') {
        runRaceCoordinator($scenario);

        return;
    }
    bootstrapApplication();
    $safety = assertSafeConnection();
    echo 'mysql_safety='.json_encode($safety, JSON_THROW_ON_ERROR).PHP_EOL;

    $token = 'SS004_'.date('Ymd_His').'_'.getmypid().'_'.bin2hex(random_bytes(4));
    $directory = storage_path('framework/testing/pm-ss004/'.$token);
    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create proof directory.');
    }

    $fixture = createFixture($token);
    writeJson($directory.'/fixture.json', $fixture);
    echo 'fixture='.json_encode($fixture, JSON_THROW_ON_ERROR).PHP_EOL;

    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, 'worker', $directory.'/fixture.json', $directory, $directory.'/worker.result.json']));
    $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['file', $directory.'/worker.stdout.log', 'w'], 2 => ['file', $directory.'/worker.stderr.log', 'w']], $pipes, dirname(__DIR__, 3));
    if (! is_resource($process)) {
        cleanupFixture($fixture);
        throw new RuntimeException('Unable to start Worker A.');
    }

    try {
        waitForFile($directory.'/barrier.json');
        $status = proc_get_status($process);
        $pausedEvidence = is_file($directory.'/worker.started')
            && ! is_file($directory.'/release.marker')
            && ! is_file($directory.'/worker.resumed');
        if (! $status['running'] || ! $pausedEvidence) {
            throw new RuntimeException('Worker A was not proven paused at barrier.');
        }
        echo 'barrier_pause='.json_encode(['worker_running' => $status['running'], 'start_completed' => is_file($directory.'/worker.result.json'), 'release_present' => is_file($directory.'/release.marker')], JSON_THROW_ON_ERROR).PHP_EOL;

        writeMarker($directory.'/release.marker');
        waitForFile($directory.'/worker.resumed');
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Worker A exited with code '.$exitCode.'.');
        }
        $result = json_decode((string) file_get_contents($directory.'/worker.result.json'), true, 512, JSON_THROW_ON_ERROR);
        echo 'worker_result='.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
        assertStartResult($fixture, $result);
        cleanupFixture($fixture);
        echo 'fixture_cleanup=verified'.PHP_EOL;
        echo 'artifact_directory='.$directory.PHP_EOL;
        echo "HARNESS VERIFIED\n";
    } catch (Throwable $exception) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        echo 'fixture_cleanup=not_performed reason='.json_encode($exception->getMessage(), JSON_THROW_ON_ERROR).PHP_EOL;
        echo 'artifact_directory='.$directory.PHP_EOL;
        throw $exception;
    }
}

function runWorker(string $fixturePath, string $directory, string $resultPath, string $scenario, string $role): void
{
    bootstrapApplication();
    $safety = assertSafeConnection();
    $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    if ($role === 'b') {
        runMutationWorker($fixture, $directory, $resultPath, $scenario);

        return;
    }
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries, $directory, $fixture): void {
        $sql = preg_replace('/\s+/', ' ', strtolower($query->sql)) ?? strtolower($query->sql);
        file_put_contents($directory.'/queries.log', $sql.PHP_EOL, FILE_APPEND | LOCK_EX);
        if (str_contains($sql, 'from `pm_checksheet_standards`') || str_contains($sql, 'from pm_checksheet_standards')) {
            $queries[] = $sql;
        }
        if (str_contains($sql, 'from `pm_checksheet_standards`') && str_contains($sql, 'pm_checksheet_part_id')) {
            writeJson($directory.'/barrier.json', [
                'pid' => getmypid(),
                'timestamp' => microtime(true),
                'query' => $sql,
                'fixture_execution_id' => $fixture['execution_id'] ?? null,
            ]);
            waitForFile($directory.'/release.marker');
            writeMarker($directory.'/worker.resumed');
        }
    });

    writeMarker($directory.'/worker.started');
    $machine = Machine::query()->findOrFail($fixture['machine_id']);
    $operator = User::query()->findOrFail($fixture['operator_id']);
    $request = Request::create('/', 'POST');
    $request->setLaravelSession(app('session.store'));
    $execution = app(PmExecutionService::class)->startExecutionOnly($request, $machine, $operator);
    $fresh = $execution->fresh();
    writeJson($resultPath, [
        'database' => $safety['database'],
        'pid' => getmypid(),
        'queries' => $queries,
        'execution_id' => $fresh?->id,
        'snapshot_at' => $fresh?->definition_snapshot_at?->toISOString(),
        'execution_status' => $fresh?->status,
        'occurrence_status' => (string) DB::table('pm_schedule_dates')->where('id', $fixture['schedule_date_id'])->value('status'),
        'item_count' => PmExecutionItem::query()->where('pm_execution_id', $fresh?->id)->count(),
        'action_options_snapshot' => PmExecutionItem::query()->where('pm_execution_id', $fresh?->id)->first()?->action_options_snapshot,
        'final_action_labels' => PmAction::query()->orderBy('id')->pluck('action_label')->all(),
        'final_action_states' => PmAction::query()->orderBy('id')->pluck('is_active')->map(fn ($value): bool => (bool) $value)->all(),
    ]);
}

function runMutationWorker(array $fixture, string $directory, string $resultPath, string $scenario): void
{
    $admin = User::query()->findOrFail($fixture['admin_id']);
    $action = PmAction::query()->findOrFail($fixture['oil_action_id']);
    $request = Request::create('/', $scenario === 'deactivate-before' || $scenario === 'deactivate-between' ? 'PATCH' : 'PUT');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(static fn () => $admin);
    $service = app(PmActionService::class);
    $startedAt = microtime(true);
    $updated = $scenario === 'deactivate-before' || $scenario === 'deactivate-between'
        ? $service->deactivate($request, $action)
        : $service->update($request, $action, 'GANTI OLI BARU '.substr($fixture['token'], -8));

    writeJson($resultPath, [
        'database' => (string) DB::selectOne('SELECT DATABASE() AS database_name')->database_name,
        'pid' => getmypid(),
        'started_at' => $startedAt,
        'committed_at' => microtime(true),
        'action_label' => $updated->fresh()->action_label,
        'is_active' => (bool) $updated->fresh()->is_active,
    ]);
    writeMarker($directory.'/mutation.committed');
}

function runRaceCoordinator(string $scenario): void
{
    $allowed = ['rename-before', 'rename-between', 'deactivate-before', 'deactivate-between'];
    if (! in_array($scenario, $allowed, true)) {
        throw new InvalidArgumentException('Unknown race scenario '.$scenario);
    }

    bootstrapApplication();
    $safety = assertSafeConnection();
    echo 'mysql_safety='.json_encode($safety, JSON_THROW_ON_ERROR).PHP_EOL;
    $token = 'SS004_'.date('Ymd_His').'_'.getmypid().'_'.bin2hex(random_bytes(4));
    $directory = storage_path('framework/testing/pm-ss004/'.$token);
    mkdir($directory, 0777, true);
    $fixture = createFixture($token);
    writeJson($directory.'/fixture.json', $fixture);
    echo 'fixture='.json_encode($fixture, JSON_THROW_ON_ERROR).PHP_EOL;

    $workerA = null;
    $workerB = null;
    try {
        if (str_ends_with($scenario, '-before')) {
            $workerB = startWorker($scenario, $directory, 'b', $directory.'/b.result.json');
            waitForFile($directory.'/mutation.committed');
            $workerA = startWorker($scenario, $directory, 'a', $directory.'/a.result.json');
        } else {
            $workerA = startWorker($scenario, $directory, 'a', $directory.'/a.result.json');
            waitForFile($directory.'/barrier.json');
            $status = proc_get_status($workerA);
            if (! $status['running'] || is_file($directory.'/a.result.json') || is_file($directory.'/release.marker')) {
                throw new RuntimeException('Worker A was not paused before mutation.');
            }
            $workerB = startWorker($scenario, $directory, 'b', $directory.'/b.result.json');
            waitForFile($directory.'/mutation.committed');
        }

        writeMarker($directory.'/release.marker');
        waitForFile($directory.'/a.result.json');
        $a = json_decode((string) file_get_contents($directory.'/a.result.json'), true, 512, JSON_THROW_ON_ERROR);
        $b = json_decode((string) file_get_contents($directory.'/b.result.json'), true, 512, JSON_THROW_ON_ERROR);
        echo 'race_result='.json_encode(['scenario' => $scenario, 'worker_a' => $a, 'worker_b' => $b], JSON_THROW_ON_ERROR).PHP_EOL;
        assertRaceResult($scenario, $a, $b);
        if (is_resource($workerA)) {
            proc_close($workerA);
        }
        if (is_resource($workerB)) {
            proc_close($workerB);
        }
        cleanupFixture($fixture);
        echo 'fixture_cleanup=verified'.PHP_EOL;
        echo 'artifact_directory='.$directory.PHP_EOL;
    } catch (Throwable $exception) {
        foreach ([$workerA, $workerB] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        echo 'fixture_cleanup=not_performed reason='.json_encode($exception->getMessage(), JSON_THROW_ON_ERROR).PHP_EOL;
        echo 'artifact_directory='.$directory.PHP_EOL;
        throw $exception;
    }
}

function startWorker(string $scenario, string $directory, string $role, string $resultPath)
{
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, 'worker', $scenario, $directory.'/fixture.json', $directory, $resultPath, $role]));

    return proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['file', $directory.'/'.$role.'.stdout.log', 'w'], 2 => ['file', $directory.'/'.$role.'.stderr.log', 'w']], $pipes, dirname(__DIR__, 3));
}

function assertRaceResult(string $scenario, array $a, array $b): void
{
    if ($a['database'] !== HARNESS_DATABASE || $b['database'] !== HARNESS_DATABASE || $a['execution_status'] !== 'in_progress' || $a['occurrence_status'] !== 'in_progress' || $a['item_count'] !== 1 || $a['snapshot_at'] === null) {
        throw new RuntimeException('Unsafe or incomplete race result.');
    }
    $options = $a['action_options_snapshot'];
    $okLabel = $options[0] ?? null;
    $newLabel = $b['action_label'];
    $oldLabel = str_replace('GANTI OLI BARU ', 'GANTI OLI ', $newLabel);
    if (str_starts_with($scenario, 'rename-')) {
        $valid = $scenario === 'rename-before'
            ? $options === [$okLabel, $newLabel]
            : in_array($options, [[$okLabel, $oldLabel], [$okLabel, $newLabel]], true);
        if (! $valid) {
            throw new RuntimeException('Rename snapshot was not coherent: '.json_encode($options, JSON_THROW_ON_ERROR));
        }
    }
    if (str_starts_with($scenario, 'deactivate-')) {
        $valid = $scenario === 'deactivate-before'
            ? count($options) === 1
            : count($options) >= 1 && $options[0] === $okLabel && (count($options) === 1 || str_starts_with((string) $options[1], 'GANTI OLI '));
        if (! $valid) {
            throw new RuntimeException('Deactivation snapshot was not coherent: '.json_encode($options, JSON_THROW_ON_ERROR));
        }
    }
}

/** @return array<string,int|string> */
function createFixture(string $token): array
{
    $now = now()->startOfSecond();
    $location = Location::query()->create(['location_code' => 'SS004-'.$token, 'location_name' => 'SS004 Proof', 'is_active' => true]);
    $machine = Machine::query()->create(['location_id' => $location->id, 'machine_code' => 'SS004-'.$token, 'machine_name' => 'SS004 Machine', 'qr_token' => 'ss004-'.$token, 'is_active' => true, 'lifecycle_status' => 'active']);
    $admin = User::query()->create(['name' => 'SS004 Admin', 'username' => 'ss4a_'.substr(hash('sha256', $token), 0, 20), 'password' => Hash::make('proof'), 'role' => 'admin', 'is_active' => true]);
    $operator = User::query()->create(['name' => 'SS004 Operator', 'username' => 'ss4o_'.substr(hash('sha256', $token), 0, 20), 'password' => Hash::make('proof'), 'role' => 'operator', 'is_active' => true]);
    $checksheet = PmChecksheet::query()->create(['checksheet_code' => 'SS004-'.$token, 'checksheet_name' => 'SS004 Checksheet', 'is_active' => true, 'created_by' => $admin->id]);
    $assignment = PmChecksheetMachine::query()->create(['pm_checksheet_id' => $checksheet->id, 'machine_id' => $machine->id, 'created_by' => $admin->id]);
    $part = PmChecksheetPart::query()->create(['pm_checksheet_machine_id' => $assignment->id, 'part_name' => 'SS004 Part', 'is_active' => true]);
    $suffix = substr($token, -8);
    $okLabel = 'OK '.$suffix;
    $oilLabel = 'GANTI OLI '.$suffix;
    $okAction = PmAction::query()->create(['action_label' => $okLabel, 'is_active' => true]);
    $oilAction = PmAction::query()->create(['action_label' => $oilLabel, 'is_active' => true]);
    $actionStandard = PmChecksheetStandard::query()->create(['pm_checksheet_part_id' => $part->id, 'standard_name' => 'SS004 Action', 'input_type' => 'action', 'action_options' => [$okLabel, $oilLabel], 'is_required' => true, 'is_active' => true]);
    $actionStandard->actions()->sync([
        $okAction->id => ['position' => 1],
        $oilAction->id => ['position' => 2],
    ]);
    $schedule = PmSchedule::query()->create(['pm_checksheet_machine_id' => $assignment->id, 'operational_from' => $now->toDateString(), 'frequency_type' => 'daily', 'start_date' => $now->toDateString(), 'generate_until' => $now->copy()->addDay()->toDateString(), 'is_active' => true, 'lifecycle_status' => 'active', 'created_by' => $admin->id]);
    $date = PmScheduleDate::query()->create(['pm_schedule_id' => $schedule->id, 'machine_id' => $machine->id, 'scheduled_date' => $now->toDateString(), 'status' => 'scheduled']);

    return ['token' => $token, 'location_id' => $location->id, 'machine_id' => $machine->id, 'admin_id' => $admin->id, 'operator_id' => $operator->id, 'checksheet_id' => $checksheet->id, 'assignment_id' => $assignment->id, 'part_id' => $part->id, 'action_standard_id' => $actionStandard->id, 'ok_action_id' => $okAction->id, 'oil_action_id' => $oilAction->id, 'schedule_id' => $schedule->id, 'schedule_date_id' => $date->id];
}

/** @param array<string,int|string> $fixture */
function assertStartResult(array $fixture, array $result): void
{
    if (($result['database'] ?? null) !== HARNESS_DATABASE || $result['execution_id'] === null || $result['snapshot_at'] === null || $result['execution_status'] !== 'in_progress' || $result['occurrence_status'] !== 'in_progress' || $result['item_count'] !== 1) {
        throw new RuntimeException('Start result did not satisfy the proof contract: '.json_encode($result, JSON_THROW_ON_ERROR));
    }
}

/** @param array<string,int|string> $fixture */
function cleanupFixture(array $fixture): void
{
    assertSafeConnection();
    DB::table('pm_execution_items')->whereIn('pm_execution_id', DB::table('pm_executions')->where('machine_id', $fixture['machine_id'])->pluck('id'))->delete();
    DB::table('pm_executions')->where('machine_id', $fixture['machine_id'])->delete();
    DB::table('pm_schedule_dates')->where('id', $fixture['schedule_date_id'])->delete();
    DB::table('pm_schedules')->where('id', $fixture['schedule_id'])->delete();
    DB::table('pm_checksheet_standard_actions')->where('pm_checksheet_standard_id', $fixture['action_standard_id'])->delete();
    DB::table('pm_actions')->whereIn('id', [$fixture['ok_action_id'], $fixture['oil_action_id']])->delete();
    DB::table('pm_checksheet_standards')->where('pm_checksheet_part_id', $fixture['part_id'])->delete();
    DB::table('pm_checksheet_parts')->where('id', $fixture['part_id'])->delete();
    DB::table('pm_checksheet_machines')->where('id', $fixture['assignment_id'])->delete();
    DB::table('pm_checksheets')->where('id', $fixture['checksheet_id'])->delete();
    DB::table('machines')->where('id', $fixture['machine_id'])->delete();
    DB::table('locations')->where('id', $fixture['location_id'])->delete();
    DB::table('users')->whereIn('id', [$fixture['admin_id'], $fixture['operator_id']])->delete();
}

function cleanupByToken(string $token): void
{
    $location = Location::query()->where('location_code', 'SS004-'.$token)->first();
    if (! $location) {
        return;
    }

    $machine = Machine::query()->where('location_id', $location->id)->first();
    $checksheet = PmChecksheet::query()->where('checksheet_code', 'SS004-'.$token)->first();
    if ($machine) {
        $executionIds = PmExecution::withTrashed()->where('machine_id', $machine->id)->pluck('id');
        DB::table('pm_execution_items')->whereIn('pm_execution_id', $executionIds)->delete();
        PmExecution::withTrashed()->whereIn('id', $executionIds)->forceDelete();
        PmScheduleDate::query()->where('machine_id', $machine->id)->delete();
        $assignmentIds = PmChecksheetMachine::query()->where('machine_id', $machine->id)->pluck('id');
        $partIds = PmChecksheetPart::query()->whereIn('pm_checksheet_machine_id', $assignmentIds)->pluck('id');
        $standardIds = PmChecksheetStandard::query()->whereIn('pm_checksheet_part_id', $partIds)->pluck('id');
        $actionIds = DB::table('pm_checksheet_standard_actions')->whereIn('pm_checksheet_standard_id', $standardIds)->pluck('pm_action_id');
        DB::table('pm_checksheet_standard_actions')->whereIn('pm_checksheet_standard_id', $standardIds)->delete();
        DB::table('pm_actions')->whereIn('id', $actionIds)->delete();
        PmChecksheetStandard::query()->whereIn('id', $standardIds)->delete();
        PmChecksheetPart::query()->whereIn('id', $partIds)->delete();
        PmSchedule::withTrashed()->where('pm_checksheet_machine_id', $assignmentIds->first())->forceDelete();
        PmChecksheetMachine::query()->whereIn('id', $assignmentIds)->delete();
    }
    if ($checksheet) {
        PmChecksheet::query()->whereKey($checksheet->id)->delete();
    }
    User::query()->whereIn('username', [
        'ss4a_'.substr(hash('sha256', $token), 0, 20),
        'ss4o_'.substr(hash('sha256', $token), 0, 20),
    ])->delete();
    Location::query()->whereKey($location->id)->delete();
}

function waitForFile(string $path): void
{
    $deadline = microtime(true) + HARNESS_TIMEOUT;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timeout waiting for '.$path);
        }
        usleep(50_000);
    }
}

function writeMarker(string $path): void
{
    file_put_contents($path, sprintf('%.6f', microtime(true)), LOCK_EX);
}

/** @param array<string,mixed> $value */
function writeJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
}
