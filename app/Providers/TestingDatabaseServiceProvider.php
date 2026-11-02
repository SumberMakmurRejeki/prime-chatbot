<?php

namespace App\Providers;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Throwable;

class TestingDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Satu-satunya database MySQL yang disetujui sebagai target testing/QA PRIME Google.
     */
    public const APPROVED_MYSQL_DATABASE = 'db_preventive_maintenance_ai_test';

    /**
     * Target database PRIME asli yang dilarang untuk runtime maupun QA PRIME Google.
     * Ditolak pada semua environment agar tidak ada konfigurasi default yang
     * diam-diam menulis ke database operasional atau QA milik PRIME asli.
     *
     * @var list<string>
     */
    public const FORBIDDEN_DATABASES = ['db_preventive_maintenance', 'db_preventive_maintenance_test'];

    /**
     * Koneksi sekunder untuk tabel push_subscriptions; target database tetap
     * tunduk pada guard database PRIME Google di atas.
     */
    public const SECONDARY_CONNECTION_KEYS = ['webpush.database_connection'];

    public function register(): void
    {
        self::enforce(
            $this->app->environment(),
            (array) config('database.connections', []),
            self::effectiveConnectionNames(),
        );
    }

    /**
     * Gagalkan bootstrapping ketika koneksi MySQL efektif menunjuk target database
     * PRIME asli (semua environment), atau ketika runtime testing tidak menunjuk ke
     * database QA PRIME Google. RuntimeException dilempar sebelum kode runtime berjalan.
     *
     * @param  array<string, array<string, mixed>>  $connections
     * @param  list<string>  $usedConnectionNames
     *
     * @throws RuntimeException
     */
    public static function enforce(string $environment, array $connections, array $usedConnectionNames): void
    {
        // Runtime testing diwajibkan menunjuk database QA PRIME Google; runtime lain
        // hanya dilarang menunjuk database PRIME asli.
        $violations = self::unapprovedMysqlConnections(
            $connections,
            $usedConnectionNames,
            $environment === 'testing',
        );

        if ($violations !== []) {
            throw new RuntimeException(implode(' ', $violations));
        }
    }

    /**
     * Nama koneksi yang benar-benar dipakai runtime testing: koneksi default ditambah
     * koneksi sekunder (tabel warisan push_subscriptions) sehingga koneksi eksplisit
     * tidak bisa mem-bypass guard.
     *
     * @return list<string>
     */
    private static function effectiveConnectionNames(): array
    {
        $names = [(string) config('database.default')];

        foreach (self::SECONDARY_CONNECTION_KEYS as $key) {
            $names[] = (string) config($key);
        }

        return array_values(array_filter(array_unique($names), static fn (string $name): bool => $name !== ''));
    }

    /**
     * Kembalikan pesan pelanggaran untuk setiap koneksi efektif bertipe MySQL yang
     * menunjuk database PRIME asli, atau (saat $requireApprovedQaTarget) yang tidak
     * menunjuk database QA PRIME Google. Pemeriksaan memakai konfigurasi efektif
     * Laravel karena variabel lingkungan proses dan DB_URL dapat menimpa .env.
     *
     * @param  array<string, array<string, mixed>>  $connections
     * @param  list<string>  $usedConnectionNames
     * @return list<string>
     */
    public static function unapprovedMysqlConnections(array $connections, array $usedConnectionNames, bool $requireApprovedQaTarget = true): array
    {
        $violations = [];
        $parser = new ConfigurationUrlParser;
        foreach ($usedConnectionNames as $name) {
            $connection = $connections[$name] ?? null;

            if (! is_array($connection)) {
                $violations[] = sprintf('Konfigurasi koneksi [%s] tidak dapat ditentukan.', $name);

                continue;
            }

            try {
                // Resolusi Laravel mencakup driver, jalur URL, dan opsi query yang menimpa konfigurasi.
                $variants = self::effectiveConfigurations($parser->parseConfiguration($connection));
            } catch (Throwable) {
                // Jangan sertakan URL atau pesan parser karena keduanya dapat memuat kredensial.
                $violations[] = sprintf('Konfigurasi koneksi [%s] tidak dapat diresolusi dengan aman.', $name);

                continue;
            }

            foreach ($variants as $variant => $effective) {
                $driver = $effective['driver'] ?? null;
                $baseDriver = $variants['base']['driver'] ?? null;

                if (! in_array($driver, ['mysql', 'mariadb', 'sqlite', 'pgsql', 'sqlsrv'], true)
                    || (in_array($baseDriver, ['mysql', 'mariadb'], true) && ! in_array($driver, ['mysql', 'mariadb'], true))) {
                    $violations[] = sprintf('Driver koneksi [%s/%s] tidak dapat ditentukan dengan aman.', $name, $variant);

                    continue;
                }

                if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                    // SQLite :memory: (PHPUnit) dan driver non-MySQL tidak dibatasi di sini.
                    continue;
                }

                $database = $effective['database'] ?? null;

                if (is_string($database) && in_array($database, self::FORBIDDEN_DATABASES, true)) {
                    $violations[] = sprintf(
                        'Koneksi [%s] varian [%s] menunjuk MySQL ke "%s"; target database PRIME asli dilarang untuk PRIME Google.',
                        $name,
                        $variant,
                        $database,
                    );

                    continue;
                }

                if ($requireApprovedQaTarget && $database !== self::APPROVED_MYSQL_DATABASE) {
                    $violations[] = sprintf(
                        'Koneksi testing [%s] varian [%s] menunjuk MySQL ke %s; hanya %s yang diizinkan.',
                        $name,
                        $variant,
                        is_string($database) ? "\"{$database}\"" : '(tanpa nama database)',
                        self::APPROVED_MYSQL_DATABASE,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, array<string, mixed>>
     */
    private static function effectiveConfigurations(array $connection): array
    {
        $variants = ['base' => $connection];

        // ConnectionFactory menimpa base lewat merge dangkal; semua kandidat acak wajib aman,
        // agar base QA tidak menyembunyikan target read/write operasional. URL hanya diresolusi sekali.
        foreach (['read', 'write'] as $type) {
            if (! array_key_exists($type, $connection)) {
                continue;
            }

            $overrides = $connection[$type];

            if (! is_array($overrides)) {
                throw new RuntimeException('Varian koneksi testing tidak dapat diresolusi.');
            }

            $candidates = isset($overrides[0]) ? $overrides : [$overrides];

            foreach ($candidates as $index => $candidate) {
                if (! is_array($candidate)) {
                    throw new RuntimeException('Kandidat koneksi testing tidak dapat diresolusi.');
                }

                $variants[$type.'.'.$index] = Arr::except(array_merge($connection, $candidate), ['read', 'write']);
            }
        }

        if (isset($connection['read']) && ! isset($connection['write'])) {
            throw new RuntimeException('Varian write koneksi testing tidak dapat ditentukan.');
        }

        return $variants;
    }
}
