<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Models\AiProviderConfiguration;
use App\Models\User;
use App\Services\AI\ProviderProbeResult;
use App\Services\AI\ProviderTestCategory;
use App\Services\Settings\AiProviderLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Bukti konkurensi AI-1B2 terhadap MySQL QA. Semua efek hanya pada baris
 * fixture milik skrip ini dan dibersihkan pada jalur sukses maupun gagal.
 * Database efektif wajib db_preventive_maintenance_ai_test.
 */
const AI1B2_DATABASE = 'db_preventive_maintenance_ai_test';

const AI1B2_TIMEOUT_SECONDS = 30;

const AI1B2_WORKER_TIMEOUT_SECONDS = 60;

$mode = $argv[1] ?? 'orchestrate';

if ($mode === 'worker') {
    try {
        ai1b2Worker($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
        exit(1);
    }
}

try {
    ai1b2Orchestrate();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    echo "AI-1B2 MYSQL CONCURRENCY PROOF - INCONCLUSIVE\n";
    exit(1);
}

function ai1b2Fail(string $message): never
{
    throw new RuntimeException($message);
}

function ai1b2Bootstrap(): void
{
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}

function ai1b2AssertDatabase(): void
{
    $database = (string) DB::connection('mysql')->selectOne('SELECT DATABASE() AS name')->name;

    if ($database !== AI1B2_DATABASE) {
        ai1b2Fail('Database efektif bukan QA: '.$database);
    }
}

function ai1b2Marker(string $directory, string $name): void
{
    file_put_contents($directory.'/'.$name, '1', LOCK_EX);
}

function ai1b2WaitMarker(string $directory, string $name): void
{
    $deadline = microtime(true) + AI1B2_TIMEOUT_SECONDS;

    while (microtime(true) < $deadline) {
        if (is_file($directory.'/'.$name)) {
            return;
        }

        usleep(25_000);
    }

    ai1b2Fail('Timeout menunggu marker '.$name);
}

/**
 * Menunggu sampai InnoDB benar-benar mencatat lock request pada baris provider
 * (Performance Schema), bukan menebak dari urutan marker atau delay tetap.
 */
function ai1b2WaitForProviderLock(int $providerId): void
{
    $deadline = microtime(true) + AI1B2_TIMEOUT_SECONDS;

    while (microtime(true) < $deadline) {
        $waitCount = (int) DB::connection('mysql')->selectOne(
            'SELECT COUNT(*) AS wait_count '
            .'FROM performance_schema.data_lock_waits AS waits '
            .'JOIN performance_schema.data_locks AS requesting '
            .'ON requesting.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID '
            .'WHERE requesting.OBJECT_SCHEMA = DATABASE() '
            .'AND requesting.OBJECT_NAME = ? '
            .'AND requesting.LOCK_DATA = ? '
            .'AND requesting.LOCK_STATUS = ?',
            ['ai_provider_configurations', (string) $providerId, 'WAITING'],
        )->wait_count;

        if ($waitCount > 0) {
            return;
        }

        usleep(50_000);
    }

    ai1b2Fail('Performance Schema tidak mengamati provider lock wait: '.$providerId);
}

/**
 * Lingkungan worker mewarisi seluruh variabel proses induk lalu menimpa target
 * DB QA; menimpa hanya dengan pasangan DB membuat PHP hilang SystemRoot/PATH.
 *
 * @return array{process: resource, role: string, directory: string}
 */
function ai1b2Spawn(string $scenario, string $role, string $directory): array
{
    $environment = array_merge(getenv(), [
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => AI1B2_DATABASE,
    ]);

    $command = implode(' ', array_map('escapeshellarg', [
        PHP_BINARY,
        __FILE__,
        'worker',
        $scenario,
        $role,
        $directory,
    ]));

    $process = proc_open($command, [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $directory.'/'.$role.'.stdout.log', 'w'],
        2 => ['file', $directory.'/'.$role.'.stderr.log', 'w'],
    ], $pipes, dirname(__DIR__, 3), $environment);

    if (! is_resource($process)) {
        ai1b2Fail('Worker tidak dapat dijalankan.');
    }

    return ['process' => $process, 'role' => $role, 'directory' => $directory];
}

/** @param array{process: resource, role: string, directory: string} $worker
 * @return array<string, mixed>
 */
function ai1b2Join(array $worker): array
{
    $deadline = microtime(true) + AI1B2_WORKER_TIMEOUT_SECONDS;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($worker['process']);

        if (! $status['running']) {
            proc_close($worker['process']);

            $stderr = is_file($worker['directory'].'/'.$worker['role'].'.stderr.log')
                ? (string) file_get_contents($worker['directory'].'/'.$worker['role'].'.stderr.log')
                : '';
            $path = $worker['directory'].'/'.$worker['role'].'.json';

            if (! is_file($path)) {
                ai1b2Fail('Worker '.$worker['role'].' tidak menulis hasil: '.$stderr);
            }

            $result = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            if (($result['ok'] ?? false) !== true) {
                ai1b2Fail('Worker '.$worker['role'].' gagal: '.($result['error'] ?? $stderr));
            }

            return $result;
        }

        usleep(25_000);
    }

    proc_terminate($worker['process']);
    ai1b2Fail('Worker '.$worker['role'].' melewati batas waktu.');
}

function ai1b2FixturePath(string $directory): string
{
    return $directory.'/fixture.json';
}

/**
 * Fixture milik skrip ini saja: satu provider chat, satu user penguji, dua
 * revisi. Semua ID dikembalikan agar pembersihan tetap lengkap.
 *
 * @return array{directory: string, token: string, provider: int, user: int, revisions: list<int>}
 */
function ai1b2CreateFixtures(string $directory): array
{
    $token = 'AI1B2_'.date('Ymd_His').'_'.getmypid().'_'.bin2hex(random_bytes(4));

    // User penguji dibuat lebih dahulu agar kegagalan pembuatan provider tidak
    // meninggalkan baris penguji tanpa jejak pembersihan.
    $user = User::factory()->create([
        'name' => 'AI1B2 Proof Manager',
        'username' => 'ai1b2-proof-'.$token,
        'role' => 'admin',
        'is_active' => true,
        'manage_ai' => true,
    ]);

    $provider = AiProviderConfiguration::query()->create([
        'display_name' => 'AI1B2 PROOF '.$token,
        'purpose' => 'chat',
    ]);

    // Fixture ditulis ke disk segera setelah provider ada supaya orchestrator
    // tetap dapat membersihkan baris parsial bila pembuatan revisi gagal.
    $fixture = [
        'directory' => $directory,
        'token' => $token,
        'provider' => (int) $provider->id,
        'user' => (int) $user->id,
        'revisions' => [],
    ];
    ai1b2WriteFixture($directory, $fixture);

    $revisions = [];
    for ($number = 1; $number <= 2; $number++) {
        // revision_number tidak masuk $fillable model AI-1B1, jadi nilai fixture
        // ditulis lewat forceFill tanpa mengubah kontrak model.
        $revision = $provider->revisions()->make();
        $revision->forceFill([
            'revision_number' => $number,
            'driver' => 'openai-compatible',
            'model_id' => 'vendor/proof-model',
            'base_url' => 'https://provider.example.test/v1',
            'credential' => 'proof-secret-value',
            'generation_parameters' => ['temperature' => 0.0, 'max_tokens' => 64],
            'enabled' => true,
        ])->save();
        $revisions[] = (int) $revision->id;
        $fixture['revisions'] = $revisions;
        ai1b2WriteFixture($directory, $fixture);
    }

    return $fixture;
}

/** @param array<string, mixed> $fixture */
function ai1b2WriteFixture(string $directory, array $fixture): void
{
    file_put_contents(ai1b2FixturePath($directory), json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX);
}

/** @param array<string, mixed> $fixture
 * @return list<string>
 */
function ai1b2Cleanup(array $fixture): array
{
    $failures = [];
    $providerId = (int) $fixture['provider'];

    foreach ([
        'pointer dilepas' => fn () => DB::table('ai_provider_configurations')->where('id', $providerId)->update([
            'active_revision_id' => null,
            'previous_known_good_revision_id' => null,
        ]),
        'revisi dihapus' => fn () => DB::table('ai_provider_configuration_revisions')->where('configuration_id', $providerId)->delete(),
        'provider dihapus' => fn () => DB::table('ai_provider_configurations')->where('id', $providerId)->delete(),
        'audit dihapus' => fn () => DB::table('user_activity_logs')
            ->where('module_name', 'ai_provider_configuration')
            ->where('record_id', $providerId)
            ->delete(),
        'user penguji dihapus' => fn () => DB::table('users')->where('id', (int) $fixture['user'])->delete(),
    ] as $label => $operation) {
        try {
            $operation();
        } catch (Throwable $exception) {
            $failures[] = $label.': '.$exception->getMessage();
        }
    }

    if (DB::table('ai_provider_configurations')->where('id', $providerId)->exists()) {
        $failures[] = 'provider masih tersisa';
    }

    if (DB::table('ai_provider_configuration_revisions')->where('configuration_id', $providerId)->exists()) {
        $failures[] = 'revisi masih tersisa';
    }

    return $failures;
}

/** @param array<string, mixed> $fixture */
function ai1b2SeedTestingAttempt(array $fixture, int $revisionId): void
{
    DB::table('ai_provider_configuration_revisions')->where('id', $revisionId)->update([
        'test_state' => 'testing',
        'test_attempt_id' => (string) Str::uuid(),
        'test_started_at' => now(),
        'tested_at' => null,
        'test_failure_category' => null,
        'tested_embedding_dimensions' => null,
    ]);
}

/** @param array<string, mixed> $fixture */
function ai1b2SeedPassedRevision(array $fixture, int $revisionId): void
{
    DB::table('ai_provider_configuration_revisions')->where('id', $revisionId)->update([
        'test_state' => 'passed',
        'test_attempt_id' => (string) Str::uuid(),
        'test_started_at' => now(),
        'tested_at' => now(),
        'test_failure_category' => null,
        'tested_embedding_dimensions' => null,
    ]);
}

/** @param array<string, mixed> $fixture */
function ai1b2RevisionValue(array $fixture, string $column): mixed
{
    return DB::table('ai_provider_configuration_revisions')->where('id', $fixture['revisions'][0])->value($column);
}

/** @param array<string, mixed> $fixture */
function ai1b2AssertAuditSafe(array $fixture): void
{
    $rows = DB::table('user_activity_logs')
        ->where('module_name', 'ai_provider_configuration')
        ->where('record_id', (int) $fixture['provider'])
        ->orderBy('id')
        ->get();

    if ($rows->isEmpty()) {
        ai1b2Fail('Audit lifecycle tidak ditemukan.');
    }

    foreach ($rows as $row) {
        $serialized = json_encode([$row->action, $row->old_values, $row->new_values, $row->description], JSON_THROW_ON_ERROR);

        if (str_contains($serialized, 'proof-secret-value') || str_contains($serialized, 'provider.example.test')) {
            ai1b2Fail('Audit membocorkan kredensial atau base URL pada aksi '.$row->action.'.');
        }

        if (! in_array($row->action, ['connection_test', 'activate', 'restore'], true)) {
            ai1b2Fail('Aksi audit tak terduga: '.$row->action);
        }
    }
}

/**
 * S1: dua attempt pada revisi yang sama. Attempt B ditulis setelah A mulai;
 * penyelesaian A memakai attempt lamanya dan wajib terdeteksi superseded.
 *
 * @param  array<string, mixed>  $fixture
 */
function ai1b2RunS1(string $root, array $fixture): void
{
    $directory = $root.'/s1';
    mkdir($directory);
    ai1b2WriteFixture($directory, $fixture);
    ai1b2SeedTestingAttempt($fixture, $fixture['revisions'][0]);

    $a = ai1b2Spawn('s1', 'a', $directory);
    ai1b2WaitMarker($directory, 'a_ready');
    $b = ai1b2Spawn('s1', 'b', $directory);
    ai1b2WaitMarker($directory, 'b_authoritative');
    ai1b2Marker($directory, 'release_a');

    $bResult = ai1b2Join($b);
    $aResult = ai1b2Join($a);

    if (($aResult['superseded'] ?? false) !== true) {
        ai1b2Fail('S1: penyelesaian attempt lama tidak ditandai superseded.');
    }

    if (($bResult['superseded'] ?? true) !== false) {
        ai1b2Fail('S1: attempt otoritatif ikut tertandai superseded.');
    }

    if (ai1b2RevisionValue($fixture, 'test_attempt_id') !== $bResult['attempt']) {
        ai1b2Fail('S1: attempt tersimpan bukan milik worker B.');
    }

    if (ai1b2RevisionValue($fixture, 'test_state') !== 'passed') {
        ai1b2Fail('S1: state akhir wajib passed.');
    }
}

/**
 * S2/S2b: dua penyelesaian bersamaan pada attempt yang sama. Penulis pertama
 * memegang lock baris provider di dalam transaksi luar; penulis kedua menunggu
 * lock tersebut lalu CAS-nya wajib gagal. Dijalankan pada dua urutan hasil.
 *
 * @param  array<string, mixed>  $fixture
 */
function ai1b2RunCompletionRace(string $root, array $fixture, bool $failureFirst): void
{
    $scenario = $failureFirst ? 's2b' : 's2';
    $directory = $root.'/'.$scenario;
    mkdir($directory);
    ai1b2WriteFixture($directory, $fixture);
    ai1b2SeedTestingAttempt($fixture, $fixture['revisions'][0]);

    $attempt = (string) ai1b2RevisionValue($fixture, 'test_attempt_id');
    file_put_contents($directory.'/attempt.txt', $attempt, LOCK_EX);
    file_put_contents($directory.'/spec.json', json_encode([
        'first_role' => $failureFirst ? 'b' : 'a',
        'expected_state' => $failureFirst ? 'failed' : 'passed',
    ], JSON_THROW_ON_ERROR), LOCK_EX);

    $first = ai1b2Spawn('s2', $failureFirst ? 'b' : 'a', $directory);
    ai1b2WaitMarker($directory, 'first_locked');
    $second = ai1b2Spawn('s2', $failureFirst ? 'a' : 'b', $directory);
    ai1b2WaitForProviderLock((int) $fixture['provider']);
    ai1b2Marker($directory, 'release_first');

    $firstResult = ai1b2Join($first);
    $secondResult = ai1b2Join($second);

    if (($firstResult['superseded'] ?? true) !== false) {
        ai1b2Fail($scenario.': penulis pertama wajib menang CAS.');
    }

    if (($secondResult['superseded'] ?? false) !== true) {
        ai1b2Fail($scenario.': penulis kedua wajib superseded.');
    }

    $expectedState = $failureFirst ? 'failed' : 'passed';
    if (ai1b2RevisionValue($fixture, 'test_state') !== $expectedState) {
        ai1b2Fail($scenario.': state akhir bukan milik penulis pertama.');
    }
}

/**
 * S3: dua aktivasi bersamaan pada revisi berbeda dengan pointer kosong.
 * Aktivasi B menunggu lock baris provider milik A; hasil akhir wajib pasangan
 * penuh (aktif revisi 2, known-good revisi 1).
 *
 * @param  array<string, mixed>  $fixture
 */
function ai1b2RunActivationRace(string $root, array $fixture): void
{
    [$revisionOne, $revisionTwo] = $fixture['revisions'];
    $directory = $root.'/s3';
    mkdir($directory);
    ai1b2WriteFixture($directory, $fixture);
    ai1b2SeedPassedRevision($fixture, $revisionOne);
    ai1b2SeedPassedRevision($fixture, $revisionTwo);
    DB::table('ai_provider_configurations')->where('id', $fixture['provider'])->update([
        'active_revision_id' => null,
        'previous_known_good_revision_id' => null,
    ]);

    $a = ai1b2Spawn('s3', 'a', $directory);
    ai1b2WaitMarker($directory, 'a_locked');
    $b = ai1b2Spawn('s3', 'b', $directory);
    ai1b2WaitForProviderLock((int) $fixture['provider']);
    ai1b2Marker($directory, 'release_a');

    ai1b2Join($a);
    ai1b2Join($b);

    $provider = AiProviderConfiguration::query()->findOrFail((int) $fixture['provider']);
    if ((int) $provider->active_revision_id !== $revisionTwo) {
        ai1b2Fail('S3: pointer aktif akhir wajib revisi 2.');
    }

    if ((int) $provider->previous_known_good_revision_id !== $revisionOne) {
        ai1b2Fail('S3: pointer known-good wajib revisi 1.');
    }
}

/**
 * S4: aktivasi A dan restore B bersamaan. Restore mengharapkan pasangan lama;
 * setelah aktivasi A commit, restore wajib 409 dan pointer tetap konsisten.
 *
 * @param  array<string, mixed>  $fixture
 */
function ai1b2RunRestoreRace(string $root, array $fixture): void
{
    [$revisionOne, $revisionTwo] = $fixture['revisions'];
    $directory = $root.'/s4';
    mkdir($directory);
    ai1b2WriteFixture($directory, $fixture);
    DB::table('ai_provider_configurations')->where('id', $fixture['provider'])->update([
        'active_revision_id' => $revisionOne,
        'previous_known_good_revision_id' => $revisionTwo,
    ]);

    $a = ai1b2Spawn('s4', 'a', $directory);
    ai1b2WaitMarker($directory, 'a_locked');
    $b = ai1b2Spawn('s4', 'b', $directory);
    ai1b2WaitForProviderLock((int) $fixture['provider']);
    ai1b2Marker($directory, 'release_a');

    ai1b2Join($a);
    $restore = ai1b2Join($b);

    if (($restore['outcome'] ?? null) !== 'conflict') {
        ai1b2Fail('S4: restore dengan pointer basi wajib 409.');
    }

    $provider = AiProviderConfiguration::query()->findOrFail((int) $fixture['provider']);
    if ((int) $provider->active_revision_id !== $revisionTwo) {
        ai1b2Fail('S4: pointer aktif wajib milik aktivasi A.');
    }

    if ((int) $provider->previous_known_good_revision_id !== $revisionOne) {
        ai1b2Fail('S4: pointer known-good wajib revisi lama yang masih layak.');
    }
}

function ai1b2Orchestrate(): void
{
    ai1b2Bootstrap();
    ai1b2AssertDatabase();
    echo 'database_identity='.AI1B2_DATABASE.PHP_EOL;

    $root = storage_path('framework/testing/ai1b2/'.date('Ymd_His').'_'.getmypid().'_'.bin2hex(random_bytes(4)));
    if (! mkdir($root, 0777, true) && ! is_dir($root)) {
        ai1b2Fail('Tidak dapat membuat direktori bukti AI-1B2.');
    }

    $fixture = [];
    $cleanupFailures = [];

    try {
        $fixture = ai1b2CreateFixtures($root);
        ai1b2WriteFixture($root, $fixture);

        ai1b2RunS1($root, $fixture);
        echo "S1 newer attempt supersedes stale completion - PASS\n";

        ai1b2RunCompletionRace($root, $fixture, false);
        echo "S2 completion race, success first - PASS\n";

        ai1b2RunCompletionRace($root, $fixture, true);
        echo "S2b completion race, failure first - PASS\n";

        ai1b2RunActivationRace($root, $fixture);
        echo "S3 Activate/Activate serialization - PASS\n";

        ai1b2RunRestoreRace($root, $fixture);
        echo "S4 Activate/Restore stale-pointer conflict - PASS\n";

        ai1b2AssertAuditSafe($fixture);
        echo "Audit contains no credential, base URL, or unexpected action - PASS\n";

        echo "AI-1B2 MYSQL CONCURRENCY PROOF - PASS\n";
        echo 'evidence_directory='.$root.PHP_EOL;
    } finally {
        // Bila pembuatan fixture berhenti di tengah jalan, jejak provider tetap
        // dibaca dari berkas fixture agar tidak ada baris parsial yang tertinggal.
        if ($fixture === [] && is_file(ai1b2FixturePath($root))) {
            $fixture = json_decode((string) file_get_contents(ai1b2FixturePath($root)), true, 512, JSON_THROW_ON_ERROR);
        }

        if ($fixture !== [] && isset($fixture['provider'], $fixture['user'])) {
            $cleanupFailures = ai1b2Cleanup($fixture);
            if ($cleanupFailures !== []) {
                fwrite(STDERR, 'Fixture cleanup failures: '.implode(' | ', $cleanupFailures).PHP_EOL);
            }
        }
    }

    if ($cleanupFailures !== []) {
        exit(1);
    }
}

/** @param array<string, mixed> $fixture */
function ai1b2Worker(string $scenario, string $role, string $directory): void
{
    if (! in_array($scenario, ['s1', 's2', 's3', 's4'], true) || ! in_array($role, ['a', 'b'], true)) {
        ai1b2Fail('Worker AI-1B2 tidak valid.');
    }

    ai1b2Bootstrap();
    ai1b2AssertDatabase();

    $fixture = json_decode((string) file_get_contents(ai1b2FixturePath($directory)), true, 512, JSON_THROW_ON_ERROR);

    try {
        $result = match ($scenario) {
            's1' => ai1b2WorkerS1($role, $directory, $fixture),
            's2' => ai1b2WorkerS2($role, $directory, $fixture),
            's3' => ai1b2WorkerS3($role, $directory, $fixture),
            's4' => ai1b2WorkerS4($role, $directory, $fixture),
        };

        $result['ok'] = true;
        file_put_contents($directory.'/'.$role.'.json', json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    } catch (Throwable $exception) {
        file_put_contents($directory.'/'.$role.'.json', json_encode([
            'ok' => false,
            'error' => $exception::class.': '.$exception->getMessage(),
        ], JSON_THROW_ON_ERROR), LOCK_EX);

        throw $exception;
    }
}

/**
 * ActivityLogService membaca session dari request; request CLI perlu toko
 * session array agar audit tetap berjalan di worker.
 */
function ai1b2Request(): Request
{
    $request = Request::create('/ai1b2-proof');
    $request->setLaravelSession(app('session.store'));

    return $request;
}

function ai1b2Complete(AiProviderLifecycleService $service, int $providerId, int $revisionId, string $attemptId, ProviderProbeResult $result): ProviderProbeResult
{
    $method = new ReflectionMethod($service, 'complete');

    return $method->invoke($service, ai1b2Request(), $providerId, $revisionId, $attemptId, 'chat', $result);
}

/** @param array<string, mixed> $fixture

 */
function ai1b2WorkerS1(string $role, string $directory, array $fixture): array
{
    $revisionId = (int) $fixture['revisions'][0];
    $providerId = (int) $fixture['provider'];
    $service = app(AiProviderLifecycleService::class);

    if ($role === 'a') {
        $staleAttempt = (string) DB::table('ai_provider_configuration_revisions')->where('id', $revisionId)->value('test_attempt_id');
        ai1b2Marker($directory, 'a_ready');
        ai1b2WaitMarker($directory, 'release_a');

        $result = ai1b2Complete($service, $providerId, $revisionId, $staleAttempt, ProviderProbeResult::success());

        return ['superseded' => $result->superseded];
    }

    ai1b2WaitMarker($directory, 'a_ready');
    $freshAttempt = (string) Str::uuid();
    DB::table('ai_provider_configuration_revisions')->where('id', $revisionId)->update([
        'test_state' => 'testing',
        'test_attempt_id' => $freshAttempt,
        'test_started_at' => now(),
        'tested_at' => null,
        'test_failure_category' => null,
    ]);
    ai1b2Marker($directory, 'b_authoritative');

    $result = ai1b2Complete($service, $providerId, $revisionId, $freshAttempt, ProviderProbeResult::success());

    return ['superseded' => $result->superseded, 'attempt' => $freshAttempt];
}

/** @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function ai1b2WorkerS2(string $role, string $directory, array $fixture): array
{
    $providerId = (int) $fixture['provider'];
    $revisionId = (int) $fixture['revisions'][0];
    $attempt = (string) file_get_contents($directory.'/attempt.txt');
    $spec = json_decode((string) file_get_contents($directory.'/spec.json'), true, 512, JSON_THROW_ON_ERROR);
    $isFirst = $role === $spec['first_role'];
    $service = app(AiProviderLifecycleService::class);

    $outcome = $isFirst
        ? ($spec['expected_state'] === 'failed' ? ProviderProbeResult::failure(ProviderTestCategory::ProviderUnavailable) : ProviderProbeResult::success())
        : ($spec['expected_state'] === 'failed' ? ProviderProbeResult::success() : ProviderProbeResult::failure(ProviderTestCategory::ProviderUnavailable));

    $connection = DB::connection('mysql');
    $connection->beginTransaction();

    try {
        $completed = ai1b2Complete($service, $providerId, $revisionId, $attempt, $outcome);

        if ($isFirst) {
            // Lock baris provider tetap dipegang transaksi luar ini sampai
            // orchestrator terbukti mengamati penulis kedua menunggu.
            ai1b2Marker($directory, 'first_locked');
            ai1b2WaitMarker($directory, 'release_first');
        }

        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        throw $exception;
    }

    return ['superseded' => $completed->superseded];
}

/** @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function ai1b2WorkerS3(string $role, string $directory, array $fixture): array
{
    $target = (int) $fixture['revisions'][$role === 'a' ? 0 : 1];
    $service = app(AiProviderLifecycleService::class);
    $connection = DB::connection('mysql');
    $connection->beginTransaction();

    try {
        // Worker B announces before its call blocks behind A's provider lock.
        if ($role === 'b') {
            ai1b2Marker($directory, 'b_started');
        }

        $service->activate(ai1b2Request(), AiProviderConfiguration::query()->findOrFail((int) $fixture['provider']), $target);

        if ($role === 'a') {
            ai1b2Marker($directory, 'a_locked');
            ai1b2WaitMarker($directory, 'release_a');
        }

        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        throw $exception;
    }

    return ['active' => $target];
}

/** @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function ai1b2WorkerS4(string $role, string $directory, array $fixture): array
{
    [$oldActive, $oldPrevious] = $fixture['revisions'];
    $service = app(AiProviderLifecycleService::class);

    if ($role === 'a') {
        $connection = DB::connection('mysql');
        $connection->beginTransaction();

        try {
            $service->activate(ai1b2Request(), AiProviderConfiguration::query()->findOrFail((int) $fixture['provider']), (int) $oldPrevious);
            ai1b2Marker($directory, 'a_locked');
            ai1b2WaitMarker($directory, 'release_a');
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return ['outcome' => 'activated'];
    }

    // Pengumuman sebelum restore: panggilan akan menunggu lock milik worker A.
    ai1b2Marker($directory, 'b_started');

    try {
        $service->restore(ai1b2Request(), AiProviderConfiguration::query()->findOrFail((int) $fixture['provider']), (int) $oldActive, (int) $oldPrevious);

        return ['outcome' => 'restored'];
    } catch (ConflictHttpException) {
        return ['outcome' => 'conflict'];
    }
}
