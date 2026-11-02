<?php

declare(strict_types=1);

/**
 * BR-PM-LC-003 bounded two-process MySQL proof.
 *
 * Membuktikan pada InnoDB nyata bahwa retirement yang COMMIT setelah command
 * cron memilih kandidat membuat CAS final gagal: occurrence tetap `scheduled`,
 * tanpa notifikasi, tanpa counter, dan tanpa execution.
 *
 * Jalankan salah satu mode secara eksplisit:
 *   ... php tests/Support/PM-LC-003/mysql-retirement-vs-scheduler-proof.php negative-control
 *   ... php tests/Support/PM-LC-003/mysql-retirement-vs-scheduler-proof.php fixed
 */

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Services\PM\MachineLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const PMLC003_DATABASE = 'db_preventive_maintenance_ai_test';
const PMLC003_TIMEOUT_SECONDS = 45;

$mode = $argv[1] ?? null;

if ($mode === 'worker') {
    try {
        runWorker($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
        exit(0);
    } catch (Throwable $exception) {
        $directory = $argv[4] ?? '';
        if ($directory !== '') {
            writeJson($directory.'/'.($argv[3] ?? 'unknown').'.error.json', [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
        fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
        exit(1);
    }
}

if (! in_array($mode, ['fixed', 'negative-control'], true)) {
    fwrite(STDERR, "Usage: php mysql-retirement-vs-scheduler-proof.php fixed|negative-control\n");
    exit(2);
}

try {
    runOrchestrator($mode);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    echo "STOP — PROOF INCONCLUSIVE\n";
    exit(1);
}

function runOrchestrator(string $mode): void
{
    bootstrapApplication();
    assertSafeConnection();
    assertInnoDbTables();

    if (getenv('PMLC003_WRITERS_QUIESCED') !== '1') {
        throw new RuntimeException('STOP: set PMLC003_WRITERS_QUIESCED=1 setelah writer scheduler/PM quiesced.');
    }

    // Sebelum fixture dibuat, tidak boleh ada kandidat ambient yang dapat mengaburkan interleave.
    $ambientCandidates = eligibleCandidateIds();
    if ($ambientCandidates !== []) {
        throw new RuntimeException('STOP: ditemukan occurrence eligible non-owned: '.implode(',', $ambientCandidates).'.');
    }

    $token = 'PMLC003_'.date('Ymd_His').'_'.getmypid().'_'.bin2hex(random_bytes(4));
    $directory = storage_path('framework/testing/pmlc003/'.$token);
    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Tidak dapat membuat direktori bukti PM-LC-003.');
    }

    $controlCounts = controlCounts();
    $fixture = [];
    $cleanupEvidence = [];
    $proofPassed = false;
    $workers = [];

    try {
        $fixture = createFixture($token);
        $ownedCandidates = eligibleCandidateIds();
        if ($ownedCandidates !== [$fixture['schedule_date']]) {
            throw new RuntimeException('STOP: candidate universe bukan tepat satu occurrence owned.');
        }
        writeJson($directory.'/fixture.json', $fixture + ['owned_candidates' => $ownedCandidates]);

        $beforeAttributes = PmScheduleDate::query()->findOrFail($fixture['schedule_date'])->getAttributes();

        $workers['scheduler'] = startWorker($mode, 'scheduler', $directory);
        waitForMarker($directory, 'scheduler_candidates_selected');

        $workers['retirement'] = startWorker($mode, 'retirement', $directory);
        waitForMarker($directory, 'retirement_committed');

        writeMarker($directory, 'release_scheduler');

        foreach ($workers as $role => $process) {
            waitForWorker($process, $directory, $role);
            unset($workers[$role]);
        }

        $schedulerResult = readJson($directory.'/scheduler.result.json');
        $output = (string) ($schedulerResult['output'] ?? '');
        if ($mode === 'fixed') {
            if (! str_contains($output, 'Overdue: 0, Missed: 0')) {
                throw new RuntimeException('Command cron tidak melaporkan nol transisi: '.trim($output));
            }
            if (($schedulerResult['selected_queries'] ?? 0) !== 1) {
                throw new RuntimeException('Seleksi kandidat owned tidak terdeteksi tepat sekali.');
            }
        } else {
            if (($schedulerResult['affected_rows'] ?? 0) !== 1
                || ($schedulerResult['occurrence_status'] ?? null) !== 'overdue') {
                throw new RuntimeException('Counterfactual CAS tidak menghasilkan expected RED.');
            }
        }

        assertOwnedRetiredState($fixture, $mode);
        $afterAttributes = PmScheduleDate::query()->findOrFail($fixture['schedule_date'])->getAttributes();
        if ($mode === 'fixed' && $beforeAttributes !== $afterAttributes) {
            throw new RuntimeException('Occurrence berubah walau CAS seharusnya nol baris.');
        }
        if ($mode === 'negative-control' && $beforeAttributes['status'] !== 'scheduled') {
            throw new RuntimeException('Fixture awal negative control bukan scheduled.');
        }

        assertOwnedNoExecutionSideEffects($fixture);
        assertOwnedLifecycleAudit($fixture);
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

    $afterCleanup = controlCounts();
    if ($afterCleanup !== $controlCounts) {
        throw new RuntimeException('Control counts tidak kembali ke kondisi sebelum fixture.');
    }

    if ($proofPassed && $mode === 'fixed') {
        echo "GREEN PASS\n";
        echo "after_stale_cas=status:scheduled affected_rows:0 counters:Overdue 0, Missed 0\n";
    } elseif ($proofPassed) {
        echo "EXPECTED RED — CAUSAL CONTROL CONFIRMED\n";
        echo "counterfactual_cas=status:overdue affected_rows:1\n";
    }

    echo 'cleanup='.implode(' ', array_map(
        static fn (string $table, int $count): string => $table.':'.$count,
        array_keys($cleanupEvidence),
        $cleanupEvidence,
    ))."\n";
    echo 'control_counts_restored='.json_encode($afterCleanup, JSON_THROW_ON_ERROR)."\n";
}

function runWorker(string $mode, string $role, string $directory): void
{
    bootstrapApplication();
    assertSafeConnection();
    assertInnoDbTables();

    $fixture = readJson($directory.'/fixture.json');

    match ($role) {
        'scheduler' => $mode === 'fixed'
            ? runFixedSchedulerWorker($directory, $fixture)
            : runNegativeControlWorker($directory, $fixture),
        'retirement' => runRetirementWorker($directory, $fixture),
        default => throw new InvalidArgumentException('Unknown worker role.'),
    };
}

/**
 * Process A menjalankan command produksi; listener hanya menjadi seam sinkronisasi.
 */
function runFixedSchedulerWorker(string $directory, array $fixture): void
{
    $armed = true;
    $checkingOwnership = false;
    $selectedQueries = 0;

    DB::listen(function ($query) use (&$armed, &$checkingOwnership, &$selectedQueries, $directory, $fixture): void {
        if (! $armed || $checkingOwnership) {
            return;
        }

        $bindings = array_map(static fn (mixed $binding): string => (string) $binding, $query->bindings);
        $sql = strtolower($query->sql);
        if (! str_starts_with(ltrim($sql), 'select ')
            || ! str_contains($sql, 'pm_schedule_dates')
            || ! str_contains($sql, 'not exists')
            || ! str_contains($sql, 'scheduled_date')
            || ! str_contains($sql, 'status')
            || ! in_array('scheduled', $bindings, true)) {
            return;
        }

        $armed = false;
        $checkingOwnership = true;
        try {
            $candidateIds = eligibleCandidateIds();
        } finally {
            $checkingOwnership = false;
        }
        if ($candidateIds !== [$fixture['schedule_date']]) {
            throw new RuntimeException('STOP: listener seam kehilangan candidate ownership.');
        }

        $armed = false;
        $selectedQueries++;
        writeMarker($directory, 'scheduler_candidates_selected');
        waitForMarker($directory, 'retirement_committed');
        waitForMarker($directory, 'release_scheduler');
    });

    Artisan::call('pm:sync-schedule-status');
    writeJson($directory.'/scheduler.result.json', [
        'output' => Artisan::output(),
        'selected_queries' => $selectedQueries,
        'owned_candidate' => $fixture['schedule_date'],
    ]);
}

/**
 * Process A proof-local counterfactual: all production candidate guards remain,
 * except the parent Schedule non-ended predicate is intentionally omitted.
 */
function runNegativeControlWorker(string $directory, array $fixture): void
{
    $candidates = eligibleCandidateIds();
    if ($candidates !== [$fixture['schedule_date']]) {
        throw new RuntimeException('Negative control candidate universe kehilangan ownership.');
    }

    writeMarker($directory, 'scheduler_candidates_selected');
    waitForMarker($directory, 'retirement_committed');
    waitForMarker($directory, 'release_scheduler');

    $scheduleDate = PmScheduleDate::query()->findOrFail($fixture['schedule_date']);
    $affected = PmScheduleDate::query()
        ->whereKey($scheduleDate->getKey())
        ->where('status', 'scheduled')
        ->whereDate('scheduled_date', '<', now()->startOfDay()->toDateString())
        ->whereDoesntHave('executions', function ($query): void {
            $query->withTrashed();
        })
        // Proof-only counterfactual: deliberately omit parent lifecycle predicate.
        ->update([
            'status' => 'overdue',
            'status_changed_at' => now(),
        ]);

    writeJson($directory.'/scheduler.result.json', [
        'affected_rows' => $affected,
        'occurrence_status' => PmScheduleDate::query()->findOrFail($fixture['schedule_date'])->status,
    ]);
}

/**
 * Process B: retirement nyata pada seam layanan Machine setelah snapshot cron
 * diambil, lalu membuktikan era Schedule benar-benar berakhir.
 */
function runRetirementWorker(string $directory, array $fixture): void
{
    waitForMarker($directory, 'scheduler_candidates_selected');

    $scheduleDate = PmScheduleDate::query()->findOrFail($fixture['schedule_date']);
    if ($scheduleDate->status !== 'scheduled') {
        throw new RuntimeException('Snapshot scheduler bukan scheduled: '.$scheduleDate->status);
    }
    if (PmExecution::withTrashed()->where('pm_schedule_date_id', $scheduleDate->id)->count() !== 0) {
        throw new RuntimeException('Snapshot scheduler sudah memiliki execution.');
    }

    if (PmSchedule::query()->findOrFail($fixture['schedule'])->lifecycle_status !== 'active') {
        throw new RuntimeException('Precondition retirement gagal: Schedule bukan active.');
    }

    $request = Request::create('/', 'PATCH');
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);

    $machine = Machine::query()->findOrFail($fixture['machine']);
    app(MachineLifecycleService::class)->transition($machine, 'retired');

    $machine->refresh();
    $schedule = PmSchedule::query()->findOrFail($fixture['schedule']);

    if ($machine->lifecycle_status !== 'retired' || (bool) $machine->is_active !== false) {
        throw new RuntimeException('Machine tidak retired setelah retirement.');
    }
    if ($schedule->lifecycle_status !== 'ended' || (bool) $schedule->is_active !== false) {
        throw new RuntimeException('Era Schedule tidak berakhir setelah retirement.');
    }

    writeJson($directory.'/retirement.result.json', [
        'machine' => $machine->lifecycle_status,
        'schedule' => $schedule->lifecycle_status,
        'occurrence' => PmScheduleDate::query()->findOrFail($fixture['schedule_date'])->status,
    ]);
    writeMarker($directory, 'retirement_committed');
}

/**
 * @return array<string, int>
 */
function createFixture(string $token): array
{
    // Ledger diisi SETELAH setiap insert berhasil agar kegagalan setup parsial
    // tetap dapat membersihkan seluruh row owned yang sudah tercipta.
    $ledger = [];

    try {
        $ledger['location'] = Location::query()->create([
            'location_code' => $token.'-LOC', 'location_name' => 'PMLC003', 'is_active' => true,
        ])->id;
        $ledger['machine'] = Machine::query()->create([
            'location_id' => $ledger['location'], 'machine_code' => $token.'-MC',
            'machine_name' => 'PMLC003 Machine', 'qr_token' => $token.'-QR',
            'is_active' => true, 'lifecycle_status' => 'active',
        ])->id;
        $ledger['admin'] = User::query()->create([
            'name' => 'PMLC003 Admin', 'username' => $token.'-admin',
            'password' => Hash::make('password'), 'role' => 'admin', 'is_active' => true,
        ])->id;
        $ledger['checksheet'] = PmChecksheet::query()->create([
            'checksheet_code' => $token.'-CHK', 'checksheet_name' => 'PMLC003 Checksheet',
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
            'generate_until' => now()->addDay()->toDateString(), 'is_active' => true,
            'lifecycle_status' => 'active', 'created_by' => $ledger['admin'],
        ])->id;
        $ledger['scheduled_date'] = now()->subDay()->toDateString();
        $ledger['schedule_date'] = PmScheduleDate::query()->create([
            'pm_schedule_id' => $ledger['schedule'], 'machine_id' => $ledger['machine'],
            'scheduled_date' => $ledger['scheduled_date'], 'status' => 'scheduled',
        ])->id;

        return $ledger;
    } catch (Throwable $exception) {
        cleanupFixture($ledger);

        throw $exception;
    }
}

/**
 * @return list<int>
 */
function eligibleCandidateIds(): array
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
        ->whereHas('schedule', function ($query): void {
            $query->withTrashed()->where('lifecycle_status', '<>', 'ended');
        })
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
}

function assertOwnedRetiredState(array $fixture, string $mode): void
{
    $machine = Machine::query()->findOrFail($fixture['machine']);
    $schedule = PmSchedule::query()->findOrFail($fixture['schedule']);
    $date = PmScheduleDate::query()->findOrFail($fixture['schedule_date']);

    if ($machine->lifecycle_status !== 'retired') {
        throw new RuntimeException('Owned Machine bukan retired.');
    }
    if ($schedule->lifecycle_status !== 'ended') {
        throw new RuntimeException('Owned Schedule bukan ended.');
    }
    $expectedStatus = $mode === 'fixed' ? 'scheduled' : 'overdue';
    if ($date->status !== $expectedStatus) {
        throw new RuntimeException('Owned occurrence status salah: '.$date->status);
    }
}

function assertOwnedNoExecutionSideEffects(array $fixture): void
{
    $executionIds = ownedIds('pm_executions', 'pm_schedule_date_id', $fixture['schedule_date']);
    if ($executionIds !== []) {
        throw new RuntimeException('Proof membuat execution owned.');
    }

    foreach ([
        'pm_execution_items' => 'pm_execution_id',
        'pm_execution_media' => 'pm_execution_id',
        'pm_execution_history' => 'pm_execution_id',
    ] as $table => $column) {
        if (ownedIds($table, $column, $executionIds) !== []) {
            throw new RuntimeException('Proof membuat side effect '.$table.'.');
        }
    }

    $notifications = DB::table('notifications')
        ->where('related_table', 'pm_schedule_dates')
        ->where('related_id', $fixture['schedule_date'])
        ->count();
    if ($notifications !== 0) {
        throw new RuntimeException('Proof membuat notification owned.');
    }
}

function assertOwnedLifecycleAudit(array $fixture): void
{
    $machineAudit = (int) DB::table('user_activity_logs')
        ->where('table_name', 'machines')
        ->where('record_id', $fixture['machine'])
        ->where('action', 'lifecycle_transition')
        ->count();
    $scheduleAudit = (int) DB::table('user_activity_logs')
        ->where('table_name', 'pm_schedules')
        ->where('record_id', $fixture['schedule'])
        ->where('action', 'lifecycle_transition')
        ->count();

    if ($machineAudit !== 1 || $scheduleAudit !== 1) {
        throw new RuntimeException(sprintf(
            'Audit lifecycle owned tidak sesuai: machines=%d pm_schedules=%d.',
            $machineAudit,
            $scheduleAudit,
        ));
    }
}

/**
 * @return array<string, int>
 */
function controlCounts(): array
{
    return [
        'locations' => (int) DB::table('locations')->count(),
        'machines' => (int) DB::table('machines')->count(),
        'pm_checksheets' => (int) DB::table('pm_checksheets')->count(),
        'pm_checksheet_machines' => (int) DB::table('pm_checksheet_machines')->count(),
        'pm_schedules' => (int) DB::table('pm_schedules')->count(),
        'pm_schedule_dates' => (int) DB::table('pm_schedule_dates')->count(),
        'pm_executions' => (int) DB::table('pm_executions')->count(),
        'pm_execution_items' => (int) DB::table('pm_execution_items')->count(),
        'pm_execution_media' => (int) DB::table('pm_execution_media')->count(),
        'pm_execution_history' => (int) DB::table('pm_execution_history')->count(),
        'notifications' => (int) DB::table('notifications')->count(),
        'notification_reads' => (int) DB::table('notification_reads')->count(),
        'user_activity_logs' => (int) DB::table('user_activity_logs')->count(),
        'users' => (int) DB::table('users')->count(),
    ];
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
    $auditIds = [];

    if (isset($fixture['schedule_date'])) {
        $executionIds = ownedIds('pm_executions', 'pm_schedule_date_id', $fixture['schedule_date']);
        $notificationIds = DB::table('notifications')
            ->where('related_table', 'pm_schedule_dates')
            ->where('related_id', $fixture['schedule_date'])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    if (isset($fixture['machine']) || isset($fixture['schedule'])) {
        $auditIds = DB::table('user_activity_logs')
            ->where(function ($query) use ($fixture): void {
                if (isset($fixture['machine'])) {
                    $query->orWhere(function ($query) use ($fixture): void {
                        $query->where('table_name', 'machines')->where('record_id', $fixture['machine']);
                    });
                }
                if (isset($fixture['schedule'])) {
                    $query->orWhere(function ($query) use ($fixture): void {
                        $query->where('table_name', 'pm_schedules')->where('record_id', $fixture['schedule']);
                    });
                }
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    $ownedIds = [
        'notification_reads' => $notificationIds === [] ? [] : ownedIds('notification_reads', 'notification_id', $notificationIds),
        'notifications' => $notificationIds,
        'user_activity_logs' => $auditIds,
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

    $userIds = array_values(array_filter([$fixture['admin'] ?? null]));
    if ($userIds !== []) {
        attemptCleanup($failures, 'users', static fn (): int => DB::table('users')->whereIn('id', $userIds)->delete());
    }

    $residualCounts = [];
    foreach ($ownedIds as $table => $ids) {
        $residualCounts[$table] = $ids === [] ? 0 : (int) DB::table($table)->whereIn('id', $ids)->count();
    }
    foreach ($parentRecords as $table => $id) {
        $residualCounts[$table] = $id === null ? 0 : (int) DB::table($table)->where('id', $id)->count();
    }
    $residualCounts['users'] = $userIds === [] ? 0 : (int) DB::table('users')->whereIn('id', $userIds)->count();

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
    $deadline = microtime(true) + PMLC003_TIMEOUT_SECONDS;
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
    if ($database !== PMLC003_DATABASE || DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Unsafe database identity: '.$database);
    }
}

function assertInnoDbTables(): void
{
    $engines = DB::table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', PMLC003_DATABASE)
        ->whereIn('TABLE_NAME', ['machines', 'pm_schedules', 'pm_schedule_dates'])
        ->pluck('ENGINE', 'TABLE_NAME')
        ->map(static fn (mixed $engine): string => (string) $engine)
        ->all();

    foreach (['machines', 'pm_schedules', 'pm_schedule_dates'] as $table) {
        if (($engines[$table] ?? null) !== 'InnoDB') {
            throw new RuntimeException('Tabel '.$table.' bukan InnoDB: '.($engines[$table] ?? 'missing'));
        }
    }
}

function startWorker(string $mode, string $role, string $directory)
{
    $command = implode(' ', array_map('escapeshellarg', [
        PHP_BINARY,
        __FILE__,
        'worker',
        $mode,
        $role,
        $directory,
    ]));
    $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start '.$role.' worker.');
    }

    return $process;
}

function waitForMarker(string $directory, string $marker): void
{
    $deadline = microtime(true) + PMLC003_TIMEOUT_SECONDS;
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
