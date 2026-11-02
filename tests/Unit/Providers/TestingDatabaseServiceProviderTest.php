<?php

namespace Tests\Unit\Providers;

use App\Providers\TestingDatabaseServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connectors\ConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class TestingDatabaseServiceProviderTest extends TestCase
{
    public function test_mysql_connection_to_approved_qa_database_is_allowed(): void
    {
        $violations = TestingDatabaseServiceProvider::unapprovedMysqlConnections(
            ['mysql' => ['driver' => 'mysql', 'database' => 'db_preventive_maintenance_ai_test']],
            ['mysql'],
        );

        $this->assertSame([], $violations);
    }

    public function test_mysql_connection_to_operational_database_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"db_preventive_maintenance"');

        TestingDatabaseServiceProvider::enforce(
            'testing',
            ['mysql' => ['driver' => 'mysql', 'database' => 'db_preventive_maintenance']],
            ['mysql'],
        );
    }

    public function test_any_unapproved_mysql_database_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseServiceProvider::enforce(
            'testing',
            ['mysql' => ['driver' => 'mysql', 'database' => 'some_other_db']],
            ['mysql'],
        );
    }

    public function test_mariadb_driver_is_guarded_like_mysql(): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseServiceProvider::enforce(
            'testing',
            ['mariadb' => ['driver' => 'mariadb', 'database' => 'db_preventive_maintenance']],
            ['mariadb'],
        );
    }

    public function test_sqlite_memory_connection_is_not_restricted(): void
    {
        $violations = TestingDatabaseServiceProvider::unapprovedMysqlConnections(
            ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
            ['sqlite'],
        );

        $this->assertSame([], $violations);
    }

    public function test_missing_mysql_database_name_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseServiceProvider::enforce(
            'testing',
            ['mysql' => ['driver' => 'mysql']],
            ['mysql'],
        );
    }

    public function test_mysql_url_to_unapproved_database_is_rejected_even_with_safe_database_key(): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseServiceProvider::enforce(
            'testing',
            ['mysql' => [
                'driver' => 'mysql',
                'url' => 'mysql://root@127.0.0.1:3306/db_preventive_maintenance',
                'database' => 'db_preventive_maintenance_ai_test',
            ]],
            ['mysql'],
        );
    }

    public function test_secondary_webpush_mysql_connection_cannot_bypass_guard(): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseServiceProvider::enforce(
            'testing',
            [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'mysql' => ['driver' => 'mysql', 'database' => 'db_preventive_maintenance'],
            ],
            ['sqlite', 'mysql'],
        );
    }

    public function test_operational_database_query_override_is_rejected_without_connecting(): void
    {
        $connection = [
            'driver' => 'mysql',
            'database' => 'db_preventive_maintenance_ai_test',
            'url' => 'mysql://127.0.0.1/db_preventive_maintenance_ai_test?database=db_preventive_maintenance',
        ];

        $resolved = (new ConfigurationUrlParser)->parseConfiguration($connection);

        $this->assertSame('mysql', $resolved['driver']);
        $this->assertSame('db_preventive_maintenance', $resolved['database']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"db_preventive_maintenance"');

        TestingDatabaseServiceProvider::enforce('testing', ['mysql' => $connection], ['mysql']);
    }

    public function test_approved_database_query_override_is_allowed(): void
    {
        $violations = TestingDatabaseServiceProvider::unapprovedMysqlConnections(
            ['mysql' => [
                'driver' => 'mysql',
                'database' => 'db_preventive_maintenance_test',
                'url' => 'mysql://127.0.0.1/db_preventive_maintenance_ai_test?database=db_preventive_maintenance_ai_test',
            ]],
            ['mysql'],
        );

        $this->assertSame([], $violations);
    }

    #[DataProvider('unsafeResolvedConnections')]
    public function test_unsafe_url_configuration_is_rejected(array $connection): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseServiceProvider::enforce('testing', ['mysql' => $connection], ['mysql']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unsafeResolvedConnections(): array
    {
        $qa = ['driver' => 'mysql', 'database' => 'db_preventive_maintenance_ai_test'];

        return [
            'arbitrary database query' => [array_merge($qa, ['url' => 'mysql://127.0.0.1/db_preventive_maintenance_ai_test?database=unapproved'])],
            'array database query' => [array_merge($qa, ['url' => 'mysql://127.0.0.1/db_preventive_maintenance_ai_test?database[]=unapproved'])],
            'malformed URL' => [array_merge($qa, ['url' => 'mysql://127.0.0.1:invalid/db_preventive_maintenance_ai_test'])],
            'invalid URL type' => [array_merge($qa, ['url' => ['database' => 'db_preventive_maintenance_ai_test']])],
            'invalid driver query' => [array_merge($qa, ['url' => 'mysql://127.0.0.1/db_preventive_maintenance_ai_test?driver[]=mysql'])],
            'URL changes sqlite to mysql' => [[
                'driver' => 'sqlite',
                'database' => ':memory:',
                'url' => 'mysql://127.0.0.1/db_preventive_maintenance',
            ]],
        ];
    }

    public function test_webpush_secondary_connection_rejects_operational_query_override(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Koneksi [webpush]');

        TestingDatabaseServiceProvider::enforce('testing', [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'webpush' => [
                'driver' => 'mysql',
                'database' => 'db_preventive_maintenance_ai_test',
                'url' => 'mysql://127.0.0.1/db_preventive_maintenance_ai_test?database=db_preventive_maintenance',
            ],
        ], ['sqlite', 'webpush']);
    }

    #[DataProvider('effectiveTargetMatrix')]
    public function test_every_effective_target_is_guarded_without_connecting(array $connection, bool $allowed, ?array $expectedTargets): void
    {
        if ($expectedTargets !== null) {
            $resolved = (new ConfigurationUrlParser)->parseConfiguration($connection);
            $factory = new ConnectionFactory(new Container);
            $targets = ['base' => $resolved['database']];

            foreach (['read', 'write'] as $type) {
                if (! isset($resolved[$type])) {
                    continue;
                }

                $candidates = isset($resolved[$type][0]) ? $resolved[$type] : [$resolved[$type]];
                foreach ($candidates as $index => $candidate) {
                    $config = $resolved;
                    $config[$type] = $candidate;
                    $effective = (new ReflectionMethod($factory, 'get'.ucfirst($type).'Config'))->invoke($factory, $config);
                    $targets[$type.'.'.$index] = $effective['database'] ?? null;
                }
            }

            $this->assertSame($expectedTargets, $targets);
        }

        $violations = TestingDatabaseServiceProvider::unapprovedMysqlConnections(['mysql' => $connection], ['mysql']);

        $this->assertSame($allowed, $violations === []);
        if (! $allowed) {
            $this->expectException(RuntimeException::class);
        }

        TestingDatabaseServiceProvider::enforce('testing', ['mysql' => $connection], ['mysql']);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, ?array<string, mixed>}>
     */
    public static function effectiveTargetMatrix(): array
    {
        $qa = 'db_preventive_maintenance_ai_test';
        $operational = 'db_preventive_maintenance';
        $base = ['driver' => 'mysql', 'database' => $qa];

        return [
            'write operational without read' => [$base + ['write' => ['database' => $operational]], false, ['base' => $qa, 'write.0' => $operational]],
            'read operational' => [$base + ['read' => ['database' => $operational], 'write' => []], false, ['base' => $qa, 'read.0' => $operational, 'write.0' => $qa]],
            'both QA' => [$base + ['read' => ['database' => $qa], 'write' => ['database' => $qa]], true, ['base' => $qa, 'read.0' => $qa, 'write.0' => $qa]],
            'host port inherit QA' => [$base + ['read' => ['host' => ['127.0.0.2'], 'port' => 3307], 'write' => ['host' => '127.0.0.3', 'port' => 3308]], true, ['base' => $qa, 'read.0' => $qa, 'write.0' => $qa]],
            'read arbitrary' => [$base + ['read' => ['database' => 'unapproved'], 'write' => []], false, ['base' => $qa, 'read.0' => 'unapproved', 'write.0' => $qa]],
            'write arbitrary' => [$base + ['read' => [], 'write' => ['database' => 'unapproved']], false, ['base' => $qa, 'read.0' => $qa, 'write.0' => 'unapproved']],
            'random read candidate unsafe' => [$base + ['read' => [['database' => $qa], ['database' => $operational]], 'write' => []], false, ['base' => $qa, 'read.0' => $qa, 'read.1' => $operational, 'write.0' => $qa]],
            'random write candidate unsafe' => [$base + ['read' => [], 'write' => [['database' => $qa], ['database' => 'unapproved']]], false, ['base' => $qa, 'read.0' => $qa, 'write.0' => $qa, 'write.1' => 'unapproved']],
            'all random candidates QA' => [$base + ['read' => [[], ['database' => $qa]], 'write' => [[], ['database' => $qa]]], true, ['base' => $qa, 'read.0' => $qa, 'read.1' => $qa, 'write.0' => $qa, 'write.1' => $qa]],
            'URL then read override' => [$base + ['url' => 'mysql://localhost/'.$qa.'?database='.$qa, 'read' => ['database' => $operational], 'write' => []], false, ['base' => $qa, 'read.0' => $operational, 'write.0' => $qa]],
            'URL query supplies unsafe write' => [$base + ['url' => 'mysql://localhost/'.$qa.'?read[host]=localhost&write[database]='.$operational], false, ['base' => $qa, 'read.0' => $qa, 'write.0' => $operational]],
            'nested URL is not reparsed by factory' => [$base + ['read' => ['url' => 'mysql://localhost/'.$operational], 'write' => []], true, ['base' => $qa, 'read.0' => $qa, 'write.0' => $qa]],
            'null read database' => [$base + ['read' => ['database' => null], 'write' => []], false, ['base' => $qa, 'read.0' => null, 'write.0' => $qa]],
            'array write database' => [$base + ['write' => ['database' => [$qa]]], false, null],
            'malformed read' => [$base + ['read' => 'invalid', 'write' => []], false, null],
            'null write' => [$base + ['read' => [], 'write' => null], false, null],
            'missing write for read' => [$base + ['read' => []], false, null],
            'malformed random candidate' => [$base + ['read' => [[], 'invalid'], 'write' => []], false, null],
            'invalid variant driver' => [$base + ['write' => ['driver' => 'unknown']], false, null],
            'mysql variant changes driver family' => [$base + ['write' => ['driver' => 'sqlite', 'database' => ':memory:']], false, null],
            'mariadb unsafe write' => [['driver' => 'mariadb', 'database' => $qa, 'write' => ['database' => $operational]], false, ['base' => $qa, 'write.0' => $operational]],
            'sqlite base cannot hide mysql write' => [['driver' => 'sqlite', 'database' => ':memory:', 'write' => ['driver' => 'mysql', 'database' => $operational]], false, ['base' => ':memory:', 'write.0' => $operational]],
        ];
    }

    #[DataProvider('unsafeSecondaryVariants')]
    public function test_webpush_secondary_rejects_every_unsafe_variant(array $override): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[webpush]');

        TestingDatabaseServiceProvider::enforce('testing', [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'webpush' => array_merge(['driver' => 'mysql', 'database' => 'db_preventive_maintenance_ai_test'], $override),
        ], ['sqlite', 'webpush']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unsafeSecondaryVariants(): array
    {
        return [
            'base' => [['database' => 'db_preventive_maintenance']],
            'read' => [['read' => ['database' => 'db_preventive_maintenance'], 'write' => []]],
            'write' => [['write' => ['database' => 'db_preventive_maintenance']]],
        ];
    }

    public function test_local_runtime_allows_application_database(): void
    {
        $violations = TestingDatabaseServiceProvider::unapprovedMysqlConnections(
            ['mysql' => ['driver' => 'mysql', 'database' => 'db_preventive_maintenance_ai']],
            ['mysql'],
            false,
        );

        $this->assertSame([], $violations);

        TestingDatabaseServiceProvider::enforce(
            'local',
            ['mysql' => ['driver' => 'mysql', 'database' => 'db_preventive_maintenance_ai']],
            ['mysql'],
        );
    }

    public function test_non_testing_runtime_rejects_legacy_prime_qa_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"db_preventive_maintenance_test"');

        TestingDatabaseServiceProvider::enforce(
            'local',
            ['mysql' => ['driver' => 'mysql', 'database' => 'db_preventive_maintenance_test']],
            ['mysql'],
        );
    }

    public function test_non_testing_runtime_rejects_legacy_target_hidden_behind_url_override(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"db_preventive_maintenance"');

        TestingDatabaseServiceProvider::enforce(
            'local',
            ['mysql' => [
                'driver' => 'mysql',
                'database' => 'db_preventive_maintenance_ai',
                'url' => 'mysql://127.0.0.1/db_preventive_maintenance_ai?database=db_preventive_maintenance',
            ]],
            ['mysql'],
        );
    }

    public function test_legacy_prime_qa_database_is_rejected_in_testing_runtime(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"db_preventive_maintenance_test"');

        TestingDatabaseServiceProvider::enforce(
            'testing',
            ['mysql' => ['driver' => 'mysql', 'database' => 'db_preventive_maintenance_test']],
            ['mysql'],
        );
    }
}
