<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Console\Commands\PM\SyncPmScheduleStatusCommand;
use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\PrimeNotification;
use App\Models\User;
use App\Services\PM\PmExecutionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const PMCE001_DATABASE = 'db_preventive_maintenance_ai_test';
const PMCE001_TIMEOUT_SECONDS = 45;

$mode = $argv[1] ?? 'orchestrate';

if ($mode === 'worker') {
    try {
        runWorker($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
        exit(0);
    } catch (Throwable $exception) {
        $directory = $argv[4] ?? '';
        if ($directory !== '') {
            writeJson($directory.'/'.($argv[2] ?? 'unknown').'.error.json', [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
        fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
        exit(1);
    }
}

try {
    runOrchestrator();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    echo "STOP — PROOF INCONCLUSIVE\n";
    exit(1);
}

function runOrchestrator(): void
{
    bootstrapApplication();
    assertSafeConnection();

    if (getenv('PMCE001_WRITERS_QUIESCED') !== '1') {
        throw new RuntimeException('STOP: set PMCE001_WRITERS_QUIESCED=1 after scheduler/PM writers are quiesced.');
    }

    // Sebelum fixture dibuat, database test bersama wajib tidak memiliki kandidat
    // scheduler ambient yang dapat dimutasi atau mengacaukan bukti.
    $ambient = eligibleCount();
    if ($ambient !== 0) {
        throw new RuntimeException('STOP: ditemukan occurrence eligible non-owned: '.$ambient.'.');
    }

    $token = 'PMCE001_'.date('Ymd_His').'_'.getmypid().'_'.bin2hex(random_bytes(4));
    $directory = storage_path('framework/testing/pmce001/'.$token);
    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Tidak dapat membuat direktori bukti PMCE-001.');
    }

    $fixture = createFixture($token);
    $cleanupEvidence = [];
    $proofPassed = false;
    $workers = [];

    try {
        writeJson($directory.'/fixture.json', $fixture);

        $workers['scheduler'] = startWorker('scheduler', $directory);
        waitForMarker($directory, 'scheduler_stale_snapshot_ready');

        $workers['executor'] = startWorker('executor', $directory);
        waitForMarker($directory, 'start_committed');

        assertOwnedState($fixture, 'in_progress', 1, 'in_progress');
        writeMarker($directory, 'release_scheduler');

        foreach ($workers as $role => $process) {
            waitForWorker($process, $directory, $role);
            unset($workers[$role]);
        }

        $schedulerResult = readJson($directory.'/scheduler.result.json');
        if (($schedulerResult['affected'] ?? true) !== false) {
            throw new RuntimeException('CAS stale transition harus mengembalikan affected=false.');
        }

        assertOwnedState($fixture, 'in_progress', 1, 'in_progress');
        $ownedNotifications = PrimeNotification::query()
            ->where('related_table', 'pm_schedule_dates')
            ->where('related_id', $fixture['schedule_date'])
            ->count();
        if ($ownedNotifications !== 0) {
            throw new RuntimeException('Owned occurrence menghasilkan notification tak terduga.');
        }

        $proofPassed = true;
    } finally {
        $firstFailure = null;
        foreach ($workers as $role => $process) {
            try {
                stopWorker($process, $directory, $role);
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
            }
        }

        try {
            $cleanupEvidence = cleanupFixture($fixture);
        } catch (Throwable $exception) {
            $firstFailure ??= $exception;
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    if ($proofPassed) {
        echo "PM-CE-001 bounded race proof PASS\n";
        echo "before=scheduled execution_count=0\n";
        echo "after_start=in_progress canonical_execution=in_progress execution_count=1\n";
        echo "after_stale_cas=affected_false lifecycle_unchanged notifications=0\n";
        echo 'cleanup='.implode(' ', array_map(
            static fn (string $table, int $count): string => $table.':'.$count,
            array_keys($cleanupEvidence),
            $cleanupEvidence,
        ))."\n";
    }
}

function runWorker(string $role, string $directory, string $unused): void
{
    bootstrapApplication();
    assertSafeConnection();
    $fixture = readJson($directory.'/fixture.json');

    if ($role === 'scheduler') {
        $stale = PmScheduleDate::query()->whereKey($fixture['schedule_date'])->firstOrFail();
        if ($stale->status !== 'scheduled' || PmExecution::withTrashed()->where('pm_schedule_date_id', $stale->id)->count() !== 0) {
            throw new RuntimeException('Scheduler snapshot bukan scheduled tanpa execution.');
        }
        writeMarker($directory, 'scheduler_stale_snapshot_ready');
        waitForMarker($directory, 'release_scheduler');

        $command = app(SyncPmScheduleStatusCommand::class);
        $method = new ReflectionMethod($command, 'transitionIfStillEligible');
        $method->setAccessible(true);
        $affected = $method->invoke($command, $stale, 'scheduled', 'overdue');
        writeJson($directory.'/scheduler.result.json', ['affected' => $affected]);

        return;
    }

    if ($role !== 'executor') {
        throw new InvalidArgumentException('Unknown worker role.');
    }

    $operator = User::query()->findOrFail($fixture['operator']);
    $machine = Machine::query()->findOrFail($fixture['machine']);
    $request = Request::create('/', 'GET');
    $request->setLaravelSession(app('session.store'));
    app(PmExecutionService::class)->startExecutionOnly($request, $machine, $operator);
    writeMarker($directory, 'start_committed');
}

function createFixture(string $token): array
{
    // Ledger diisi SETELAH setiap insert berhasil agar kegagalan setup parsial
    // tetap dapat membersihkan seluruh row owned yang sudah tercipta.
    $ledger = [];

    try {
        $ledger['location'] = Location::query()->create([
            'location_code' => $token.'-LOC', 'location_name' => 'PMCE001', 'is_active' => true,
        ])->id;
        $ledger['machine'] = Machine::query()->create([
            'location_id' => $ledger['location'], 'machine_code' => $token.'-MC',
            'machine_name' => 'PMCE001 Machine', 'qr_token' => $token.'-QR',
            'is_active' => true, 'lifecycle_status' => 'active',
        ])->id;
        $ledger['operator'] = User::query()->create([
            'name' => 'PMCE001 Operator', 'username' => $token.'-operator',
            'password' => Hash::make('password'), 'role' => 'operator', 'is_active' => true,
        ])->id;
        $ledger['admin'] = User::query()->create([
            'name' => 'PMCE001 Admin', 'username' => $token.'-admin',
            'password' => Hash::make('password'), 'role' => 'admin', 'is_active' => true,
        ])->id;
        $ledger['checksheet'] = PmChecksheet::query()->create([
            'checksheet_code' => $token.'-CHK', 'checksheet_name' => 'PMCE001 Checksheet',
            'is_active' => true, 'created_by' => $ledger['admin'],
        ])->id;
        $ledger['assignment'] = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $ledger['checksheet'], 'machine_id' => $ledger['machine'],
            'created_by' => $ledger['admin'],
        ])->id;
        $ledger['schedule'] = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $ledger['assignment'],
            'operational_from' => now()->subDay()->toDateString(),
            'frequency_type' => 'daily', 'start_date' => now()->subDay()->toDateString(),
            'generate_until' => now()->addMonth()->toDateString(), 'is_active' => true,
            'lifecycle_status' => 'active', 'created_by' => $ledger['admin'],
        ])->id;
        $ledger['schedule_date'] = PmScheduleDate::query()->create([
            'pm_schedule_id' => $ledger['schedule'], 'machine_id' => $ledger['machine'],
            'scheduled_date' => now()->subDay()->toDateString(), 'status' => 'scheduled',
        ])->id;

        return $ledger;
    } catch (Throwable $exception) {
        cleanupFixture($ledger);

        throw $exception;
    }
}

function eligibleCount(): int
{
    $today = now()->startOfDay();

    return PmScheduleDate::query()
        ->where(function ($query) use ($today): void {
            $query->where(function ($query) use ($today): void {
                $query->where('scheduled_date', '<', $today->toDateString())->where('status', 'scheduled');
            })->orWhere(function ($query) use ($today): void {
                $query->where('scheduled_date', '<', $today->copy()->subDay()->toDateString())->where('status', 'overdue');
            });
        })
        ->whereDoesntHave('executions', function ($query): void {
            $query->withTrashed();
        })
        ->count();
}

function assertOwnedState(array $fixture, string $status, int $executionCount, string $executionStatus): void
{
    $date = PmScheduleDate::query()->findOrFail($fixture['schedule_date']);
    $execution = PmExecution::withTrashed()->where('pm_schedule_date_id', $date->id)->get();
    if ($date->status !== $status || $execution->count() !== $executionCount || $execution->first()?->status !== $executionStatus) {
        throw new RuntimeException('Owned lifecycle state mismatch.');
    }
}

/**
 * Menghapus hanya row yang dimiliki fixture, tetap mencoba seluruh dependency
 * saat satu operasi gagal, lalu membuktikan setiap ID recorded sudah tidak ada.
 *
 * @return array<string, int>
 */
function cleanupFixture(array $fixture): array
{
    assertSafeConnection();

    $executionIds = [];
    $notificationIds = [];
    if (isset($fixture['schedule_date'])) {
        $executionIds = ownedIds('pm_executions', 'pm_schedule_date_id', $fixture['schedule_date']);
        $notificationIds = DB::table('notifications')
            ->where('related_table', 'pm_schedule_dates')
            ->where('related_id', $fixture['schedule_date'])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    $ownedIds = [
        'notification_reads' => $notificationIds === [] ? [] : ownedIds('notification_reads', 'notification_id', $notificationIds),
        'notifications' => $notificationIds,
        'user_activity_logs' => $executionIds === [] ? [] : DB::table('user_activity_logs')
            ->where('table_name', 'pm_executions')
            ->whereIn('record_id', $executionIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all(),
        'pm_execution_history' => $executionIds === [] ? [] : ownedIds('pm_execution_history', 'pm_execution_id', $executionIds),
        'pm_execution_media' => $executionIds === [] ? [] : ownedIds('pm_execution_media', 'pm_execution_id', $executionIds),
        'pm_execution_items' => $executionIds === [] ? [] : ownedIds('pm_execution_items', 'pm_execution_id', $executionIds),
        'pm_executions' => $executionIds,
    ];
    $failures = [];

    foreach ([
        'notification_reads' => static fn (): int => DB::table('notification_reads')->whereIn('id', $ownedIds['notification_reads'])->delete(),
        'notifications' => static fn (): int => DB::table('notifications')->whereIn('id', $ownedIds['notifications'])->delete(),
        'user_activity_logs' => static fn (): int => DB::table('user_activity_logs')->whereIn('id', $ownedIds['user_activity_logs'])->delete(),
        'pm_execution_history' => static fn (): int => DB::table('pm_execution_history')->whereIn('id', $ownedIds['pm_execution_history'])->delete(),
        'pm_execution_media' => static fn (): int => DB::table('pm_execution_media')->whereIn('id', $ownedIds['pm_execution_media'])->delete(),
        'pm_execution_items' => static fn (): int => DB::table('pm_execution_items')->whereIn('id', $ownedIds['pm_execution_items'])->delete(),
        'pm_executions' => static fn (): int => DB::table('pm_executions')->whereIn('id', $ownedIds['pm_executions'])->delete(),
    ] as $table => $operation) {
        if ($ownedIds[$table] !== []) {
            attemptCleanup($failures, $table, $operation);
        }
    }

    $parentRecords = [
        'pm_schedule_dates' => $fixture['schedule_date'] ?? null,
        'pm_schedules' => $fixture['schedule'] ?? null,
        'pm_checksheet_machines' => $fixture['assignment'] ?? null,
        'pm_checksheets' => $fixture['checksheet'] ?? null,
        'machines' => $fixture['machine'] ?? null,
        'locations' => $fixture['location'] ?? null,
    ];
    foreach ($parentRecords as $table => $id) {
        if ($id !== null) {
            attemptCleanup($failures, $table, static fn (): int => DB::table($table)->where('id', $id)->delete());
        }
    }

    $userIds = array_values(array_filter([$fixture['operator'] ?? null, $fixture['admin'] ?? null]));
    if ($userIds !== []) {
        attemptCleanup($failures, 'users', static fn (): int => DB::table('users')->whereIn('id', $userIds)->delete());
    }

    $residualCounts = [];
    foreach ($ownedIds as $table => $ids) {
        $residualCounts[$table] = $ids === [] ? 0 : DB::table($table)->whereIn('id', $ids)->count();
    }
    foreach ($parentRecords as $table => $id) {
        $residualCounts[$table] = $id === null ? 0 : DB::table($table)->where('id', $id)->count();
    }
    $residualCounts['users'] = $userIds === [] ? 0 : DB::table('users')->whereIn('id', $userIds)->count();

    $residuals = array_keys(array_filter($residualCounts, static fn (int $count): bool => $count !== 0));
    if ($failures !== [] || $residuals !== []) {
        throw new RuntimeException('Cleanup fixture gagal: '.implode('; ', [...$failures, ...$residuals]));
    }

    return $residualCounts;
}

/**
 * @param  array<int, string>  $failures
 */
function attemptCleanup(array &$failures, string $label, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        $failures[] = $label.': '.$exception->getMessage();
    }
}

/**
 * @param  int|array<int, int>  $value
 * @return array<int, int>
 */
function ownedIds(string $table, string $column, int|array $value): array
{
    $query = DB::table($table);
    if (is_array($value)) {
        $query->whereIn($column, $value);
    } else {
        $query->where($column, $value);
    }

    return $query->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
}

function stopWorker($process, string $directory, string $role): void
{
    $status = @proc_get_status($process);
    if (! is_array($status)) {
        return;
    }

    if ($status['running']) {
        @proc_terminate($process);
    }

    waitForWorker($process, $directory, $role);
}

function waitForWorker($process, string $directory, string $role): void
{
    $deadline = microtime(true) + PMCE001_TIMEOUT_SECONDS;
    do {
        $status = proc_get_status($process);
        if (! $status['running']) {
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $errorPath = $directory.'/'.$role.'.error.json';
                $error = is_file($errorPath) ? file_get_contents($errorPath) : 'exit '.$exitCode;
                throw new RuntimeException($role.' worker failed: '.$error);
            }

            return;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);

    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('Timeout waiting for '.$role.' worker.');
}

function bootstrapApplication(): void
{
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}

function assertSafeConnection(): void
{
    $database = (string) DB::selectOne('SELECT DATABASE() AS database_name')->database_name;
    if ($database !== PMCE001_DATABASE || DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Unsafe database identity: '.$database);
    }
}

function startWorker(string $role, string $directory)
{
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, 'worker', $role, $directory, 'unused']));
    $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start '.$role.' worker.');
    }

    return $process;
}

function waitForMarker(string $directory, string $marker): void
{
    $deadline = microtime(true) + PMCE001_TIMEOUT_SECONDS;
    while (! is_file($directory.'/'.$marker)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timeout waiting for '.$marker.'.');
        }
        usleep(50_000);
    }
}

function writeMarker(string $directory, string $marker): void
{
    file_put_contents($directory.'/'.$marker, '1', LOCK_EX);
}

function writeJson(string $path, array $payload): void
{
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
}

function readJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
