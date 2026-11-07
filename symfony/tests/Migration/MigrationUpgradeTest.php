<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The real migrations against a real SQLite file — the path every install
 * takes (docker/frankenphp/init.sh runs `doctrine:migrations:migrate` on
 * every boot). The rest of the suite builds its schema from the entities
 * with SchemaTool, so without this test nothing proves that the migrations
 * produce the schema the entities map, or that an older install's data
 * survives the upgrade.
 *
 * The mapping check is column-level (existence, nullability, declared type,
 * named indexes) rather than "the schema diff is empty": on SQLite the
 * comparator reports table rebuilds for identical DDL, and the Messenger
 * transport table is created at runtime, not by a migration.
 */
final class MigrationUpgradeTest extends KernelTestCase
{
    private const V1_0_BASELINE = 'DoctrineMigrations\Version20260419220010';
    private const V1_0_AVATAR = 'DoctrineMigrations\Version20260423220000';
    private const V1_1_INSTANCES = 'DoctrineMigrations\Version20260503000000';

    private string $dbFile;
    private Connection $db;
    private DependencyFactory $migrations;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/prismarr-migration-' . bin2hex(random_bytes(6)) . '.db';
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbFile]);
        $this->migrations = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => \dirname(__DIR__, 2) . '/migrations'],
            ]),
            new ExistingConnection($this->db),
        );
        $this->migrations->getMetadataStorage()->ensureInitialized();
    }

    protected function tearDown(): void
    {
        $this->db->close();
        if (is_file($this->dbFile)) {
            unlink($this->dbFile);
        }
        parent::tearDown();
    }

    public function testFreshInstallMatchesTheEntityMapping(): void
    {
        $this->assertGreaterThan(0, $this->migrateTo('latest'));

        $this->assertSchemaMatchesEntities();
    }

    public function testRebootAtLatestRunsNothing(): void
    {
        $this->migrateTo('latest');

        $this->assertSame(0, $this->migrateTo('latest'), 'a second boot must not re-run any migration');
    }

    public function testUpgradeFromV10KeepsDataAndMovesLegacyInstances(): void
    {
        // A v1.0.0 install: single Radarr/Sonarr kept in four settings rows.
        $this->migrateTo(self::V1_0_BASELINE);
        $this->db->insert('"user"', [
            'email' => 'owner@example.test',
            'roles' => '["ROLE_ADMIN"]',
            'password' => '$2y$13$hash',
            'display_name' => 'Owner',
            'created_at' => '2026-04-20 10:00:00',
        ]);
        $this->db->insert('media_watchlist', [
            'tmdb_id' => 603,
            'media_type' => 'movie',
            'title' => 'The Matrix',
            'vote' => 8.2,
            'year' => 1999,
            'added_at' => '2026-04-21 09:00:00',
            'notes' => 'rewatch',
        ]);
        foreach ([
            'radarr_url' => 'http://radarr:7878',
            'radarr_api_key' => 'radarr-key',
            'sonarr_url' => 'http://sonarr:8989',
            'sonarr_api_key' => '',
            'display_timezone' => 'America/Chicago',
        ] as $name => $value) {
            $this->db->insert('setting', ['name' => $name, 'value' => $value, 'updated_at' => '2026-04-20 10:00:00']);
        }

        // A v1.0.x install that has also uploaded an avatar.
        $this->migrateTo(self::V1_0_AVATAR);
        $this->db->update('"user"', ['avatar_path' => 'avatars/owner.png'], ['email' => 'owner@example.test']);

        $this->migrateTo('latest');

        $user = $this->db->fetchAssociative('SELECT email, display_name, avatar_path, roles FROM "user"');
        $this->assertSame(
            ['email' => 'owner@example.test', 'display_name' => 'Owner', 'avatar_path' => 'avatars/owner.png', 'roles' => '["ROLE_ADMIN"]'],
            $user,
        );
        $this->assertSame(
            ['title' => 'The Matrix', 'notes' => 'rewatch'],
            $this->db->fetchAssociative('SELECT title, notes FROM media_watchlist WHERE tmdb_id = 603'),
        );

        // v1.1.0 moved the legacy settings into service_instance and deleted them.
        $instances = $this->db->fetchAllAssociative(
            'SELECT type, slug, url, api_key, is_default, enabled, position, default_quality_profile_id
             FROM service_instance ORDER BY type',
        );
        $this->assertEquals([
            ['type' => 'radarr', 'slug' => 'radarr-1', 'url' => 'http://radarr:7878', 'api_key' => 'radarr-key',
             'is_default' => 1, 'enabled' => 1, 'position' => 0, 'default_quality_profile_id' => null],
            ['type' => 'sonarr', 'slug' => 'sonarr-1', 'url' => 'http://sonarr:8989', 'api_key' => null,
             'is_default' => 1, 'enabled' => 1, 'position' => 0, 'default_quality_profile_id' => null],
        ], $instances);
        $this->assertSame(
            ['display_timezone'],
            $this->db->fetchFirstColumn('SELECT name FROM setting ORDER BY name'),
            'only the four legacy instance settings are removed',
        );

        // The Wokeometer migration seeds its single state row.
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM wokeometer_sync_state WHERE id = 1'));

        $this->assertSchemaMatchesEntities();
    }

    public function testUpgradeFromV10WithoutRadarrOrSonarrCreatesNoInstance(): void
    {
        $this->migrateTo(self::V1_0_BASELINE);
        $this->db->insert('setting', ['name' => 'radarr_url', 'value' => '', 'updated_at' => '2026-04-20 10:00:00']);

        $this->migrateTo(self::V1_1_INSTANCES);

        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM service_instance'));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM setting'));
    }

    /** @return int the number of migrations executed */
    private function migrateTo(string $versionOrAlias): int
    {
        $version = $this->migrations->getVersionAliasResolver()->resolveVersionAlias($versionOrAlias);
        $plan = $this->migrations->getMigrationPlanCalculator()->getPlanUntilVersion($version);
        $this->migrations->getMigrator()->migrate($plan, new MigratorConfiguration());

        return \count($plan);
    }

    private function assertSchemaMatchesEntities(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $schema = $this->db->createSchemaManager();
        $platform = $this->db->getDatabasePlatform();

        $checked = 0;
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass) {
                continue;
            }
            $table = trim($meta->getTableName(), '`"');
            $this->assertTrue($schema->tablesExist([$table]), "{$meta->getName()}: table {$table} missing after migrating");

            $columns = [];
            foreach ($schema->listTableColumns($table) as $column) {
                $columns[strtolower($column->getName())] = $column;
            }

            $mapped = [];
            foreach ($meta->getFieldNames() as $field) {
                $name = strtolower(trim($meta->getColumnName($field), '`"'));
                $mapped[$name] = true;
                $where = "{$table}.{$name} ({$meta->getName()}::\${$field})";
                $this->assertArrayHasKey($name, $columns, "{$where}: column missing after migrating");
                $column = $columns[$name];
                $mapping = $meta->getFieldMapping($field);

                $this->assertSame(
                    $meta->isNullable($field),
                    !$column->getNotnull(),
                    "{$where}: nullability differs between the entity and the migrated schema",
                );

                $length = $mapping->length ?? null;
                $expected = Type::getType((string) $meta->getTypeOfField($field))->getSQLDeclaration(
                    ['length' => $length, 'precision' => $mapping->precision ?? null, 'scale' => $mapping->scale ?? null,
                     'fixed' => (bool) ($mapping->options['fixed'] ?? false)],
                    $platform,
                );
                $this->assertSame($expected, $this->declaredType($column, $length, $platform), "{$where}: declared type differs");

                if ($mapping->unique ?? false) {
                    $this->assertIndex($schema->listTableIndexes($table), null, [$name], true, $where);
                }
            }
            foreach ($meta->getAssociationMappings() as $association) {
                if (!$association instanceof ToOneOwningSideMapping) {
                    continue;
                }
                foreach ($association->joinColumns as $joinColumn) {
                    $name = strtolower(trim($joinColumn->name, '`"'));
                    $mapped[$name] = true;
                    $this->assertArrayHasKey($name, $columns, "{$table}.{$name}: join column missing after migrating");
                }
            }

            // A NOT NULL column the entity doesn't know about breaks every INSERT.
            foreach ($columns as $name => $column) {
                if (!isset($mapped[$name])) {
                    $this->assertFalse(
                        $column->getNotnull() && $column->getDefault() === null,
                        "{$table}.{$name}: NOT NULL column without a default that the entity does not map",
                    );
                }
            }

            $indexes = $schema->listTableIndexes($table);
            foreach (['uniqueConstraints' => true, 'indexes' => false] as $key => $unique) {
                foreach ($meta->table[$key] ?? [] as $indexName => $definition) {
                    $wanted = array_map('strtolower', $definition['columns'] ?? []);
                    $this->assertIndex($indexes, (string) $indexName, $wanted, $unique, $table);
                }
            }
            ++$checked;
        }

        $this->assertGreaterThan(0, $checked, 'no entity metadata was checked');
    }

    private function declaredType(Column $column, ?int $entityLength, AbstractPlatform $platform): string
    {
        return $column->getType()->getSQLDeclaration(
            ['length' => $entityLength === null ? null : $column->getLength(), 'precision' => $column->getPrecision(),
             'scale' => $column->getScale(), 'fixed' => $column->getFixed()],
            $platform,
        );
    }

    /**
     * @param array<Index>  $indexes
     * @param list<string>  $columns lower-case column names, in order
     */
    private function assertIndex(array $indexes, ?string $name, array $columns, bool $unique, string $where): void
    {
        foreach ($indexes as $index) {
            if ($name !== null && strcasecmp($index->getName(), $name) !== 0) {
                continue;
            }
            if (array_map('strtolower', $index->getColumns()) === $columns && (!$unique || $index->isUnique())) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail(sprintf(
            '%s: %s index %s(%s) missing after migrating',
            $where,
            $unique ? 'unique' : 'plain',
            $name ?? '',
            implode(', ', $columns),
        ));
    }
}
