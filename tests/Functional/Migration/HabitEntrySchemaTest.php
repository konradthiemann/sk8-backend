<?php

declare(strict_types=1);

namespace App\Tests\Functional\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Verifies the `habit_entry` schema migration of T-0402 (design.md §2): table,
 * columns, indexes, the foreign key, the hand-written check constraint, the
 * dry run and the down-then-up round trip. Doctrine does not know check
 * constraints, so the only way to prove `chk_habit_entry_value` is a raw
 * INSERT that the database must refuse - the same pattern as HabitSchemaTest.
 *
 * Each constraint violation gets its own test method (data provider): after
 * the first failed statement Postgres aborts the transaction, so a second
 * INSERT in the same test would fail for the wrong reason. The assertion looks
 * for the constraint's name in the message, because DBAL has no exception
 * subtype for a CHECK violation and a bare `expectException(DriverException)`
 * would already pass while the table does not exist yet.
 *
 * Red reason before the migration exists: the `habit_entry` table is missing.
 */
final class HabitEntrySchemaTest extends KernelTestCase
{
    private const string MIGRATION_DESCRIPTION = 'Create habit_entry table with its unique day index and value check';

    private const array EXPECTED_COLUMNS = [
        'created_at' => ['timestamp with time zone', 'NO'],
        'entry_date' => ['date', 'NO'],
        'habit_id' => ['uuid', 'NO'],
        'id' => ['uuid', 'NO'],
        'note' => ['text', 'YES'],
        'value_bool' => ['boolean', 'YES'],
        'value_numeric' => ['numeric', 'YES'],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testItCreatesTheTableWithAllSevenColumnsTheirTypesAndNullability(): void
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY column_name',
            ['habit_entry'],
        );

        $actual = [];
        foreach ($rows as $row) {
            $name = $row['column_name'] ?? null;
            $type = $row['data_type'] ?? null;
            $nullable = $row['is_nullable'] ?? null;
            self::assertIsString($name);
            self::assertIsString($type);
            self::assertIsString($nullable);
            $actual[$name] = [$type, $nullable];
        }

        self::assertSame(self::EXPECTED_COLUMNS, $actual);
    }

    public function testItStoresTheNumericValueAsNumericEightTwo(): void
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'habit_entry' AND column_name = 'value_numeric'",
        );

        self::assertSame(['numeric_precision' => 8, 'numeric_scale' => 2], $row);
    }

    public function testItDefinesTheUniqueIndexOnHabitAndDay(): void
    {
        $definition = $this->indexDefinition('uniq_habit_entry_habit_date');

        self::assertStringContainsString('CREATE UNIQUE INDEX', $definition);
        self::assertStringContainsString('(habit_id, entry_date)', $definition);
    }

    public function testItDefinesTheDescendingIndexOnTheDay(): void
    {
        $definition = $this->indexDefinition('idx_habit_entry_date');

        self::assertStringNotContainsString('UNIQUE', $definition);
        self::assertStringContainsString('(entry_date DESC)', $definition);
    }

    public function testItDefinesExactlyTheOneNamedCheckConstraint(): void
    {
        self::assertSame(['chk_habit_entry_value'], $this->checkConstraintNames());
    }

    public function testItDefinesTheForeignKeyToHabitWithRestrict(): void
    {
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT confrelid::regclass::text AS target, confdeltype FROM pg_constraint WHERE conrelid = 'habit_entry'::regclass AND contype = 'f'",
        );

        self::assertSame([['target' => 'habit', 'confdeltype' => 'r']], $rows);
    }

    public function testItRefusesToDeleteAHabitThatHasEntries(): void
    {
        $habitId = $this->insertHabit();
        $this->insertEntry(['habit_id' => $habitId]);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection()->executeStatement('DELETE FROM habit WHERE id = ?', [$habitId]);
    }

    public function testItRefusesAnEntryForAnUnknownHabit(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->insertEntry(['habit_id' => Uuid::v7()->toRfc4122()]);
    }

    /**
     * @param array<string, string|bool|null> $overrides
     */
    #[DataProvider('rowsViolatingTheValueCheck')]
    public function testItRejectsARowThatViolatesTheValueCheck(array $overrides): void
    {
        $habitId = $this->insertHabit();

        try {
            $this->insertEntry(['habit_id' => $habitId] + $overrides);
            self::fail('expected chk_habit_entry_value to reject the row');
        } catch (DriverException $exception) {
            self::assertStringContainsString('chk_habit_entry_value', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{array<string, string|bool|null>}>
     */
    public static function rowsViolatingTheValueCheck(): array
    {
        return [
            'both values set' => [['value_numeric' => '1.00', 'value_bool' => true]],
            'zero and no' => [['value_numeric' => '0.00', 'value_bool' => false]],
            'neither value set' => [['value_numeric' => null, 'value_bool' => null]],
        ];
    }

    /**
     * @param array<string, string|bool|null> $overrides
     */
    #[DataProvider('validRows')]
    public function testItAcceptsAValidRow(array $overrides): void
    {
        $habitId = $this->insertHabit();

        $this->insertEntry(['habit_id' => $habitId] + $overrides);

        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM habit_entry WHERE habit_id = ?', [$habitId]));
    }

    /**
     * @return array<string, array{array<string, string|bool|null>}>
     */
    public static function validRows(): array
    {
        return [
            'only a number' => [['value_numeric' => '7.50', 'value_bool' => null]],
            'zero as a number' => [['value_numeric' => '0.00', 'value_bool' => null]],
            'only yes' => [['value_numeric' => null, 'value_bool' => true]],
            'only no' => [['value_numeric' => null, 'value_bool' => false]],
            'with a note' => [['note' => 'spät ins Bett']],
        ];
    }

    public function testItRejectsASecondEntryForTheSameHabitAndDay(): void
    {
        $habitId = $this->insertHabit();
        $this->insertEntry(['habit_id' => $habitId, 'entry_date' => '2026-09-08']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_habit_entry_habit_date');

        $this->insertEntry(['habit_id' => $habitId, 'entry_date' => '2026-09-08']);
    }

    public function testItAcceptsTheSameDayForAnotherHabitAndAnotherDayForTheSameHabit(): void
    {
        $habitId = $this->insertHabit('first');
        $otherHabitId = $this->insertHabit('second');
        $this->insertEntry(['habit_id' => $habitId, 'entry_date' => '2026-09-08']);

        $this->insertEntry(['habit_id' => $otherHabitId, 'entry_date' => '2026-09-08']);
        $this->insertEntry(['habit_id' => $habitId, 'entry_date' => '2026-09-09']);

        self::assertSame(3, $this->connection()->fetchOne('SELECT count(*) FROM habit_entry'));
    }

    public function testItKeepsTheEntryDateAsACalendarDateWithoutATime(): void
    {
        $habitId = $this->insertHabit();
        $this->insertEntry(['habit_id' => $habitId, 'entry_date' => '2026-09-08']);

        self::assertSame('2026-09-08', $this->connection()->fetchOne('SELECT entry_date::text FROM habit_entry WHERE habit_id = ?', [$habitId]));
    }

    public function testItKeepsTheNumericValueAsAnExactDecimal(): void
    {
        $habitId = $this->insertHabit();
        $this->insertEntry(['habit_id' => $habitId, 'value_numeric' => '7.50']);

        self::assertSame('7.50', $this->connection()->fetchOne('SELECT value_numeric FROM habit_entry WHERE habit_id = ?', [$habitId]));
    }

    /**
     * A migration that only records its statements (`addSql()`) must not write anything on a dry run - the
     * T-0401 pattern (`executeStatement()` in `up()`) does, and then the real run fails because the table
     * already exists (design.md §2).
     *
     * The migration object of this test process must not have run before, because a run freezes the
     * object and `ReplayableMigration` then executes directly (that is what the round trip needs), so a dry
     * run on a used object would write. So the
     * "pending" state is produced without running `down()`: the table and the recorded version are removed
     * by hand. Deliberately runs outside dama's per-test rollback, like HabitSchemaTest (the migrator
     * handles its own transactions): the schema is restored by the test itself.
     *
     * Red reason before the implementation: no migration with that description exists yet.
     */
    public function testAMigrationDryRunWritesNothingAndTheRealRunStillWorks(): void
    {
        $dependencyFactory = $this->dependencyFactory();
        [, $newestVersion, $habitEntryVersion] = $this->versionsAround($dependencyFactory);

        $this->connection()->executeStatement('DROP TABLE habit_entry');
        $this->connection()->executeStatement(
            \sprintf('DELETE FROM %s WHERE version = ?', $this->versionTable($dependencyFactory)),
            [(string) $habitEntryVersion],
        );

        try {
            $dryRunPlan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($newestVersion);
            self::assertCount(1, $dryRunPlan->getItems(), 'only the habit_entry migration may be pending');
            $dependencyFactory->getMigrator()->migrate($dryRunPlan, (new MigratorConfiguration())->setDryRun(true));
            $existsAfterDryRun = $this->tableExists();
            $recordedAfterDryRun = 0 !== $this->connection()->fetchOne(
                \sprintf('SELECT count(*) FROM %s WHERE version = ?', $this->versionTable($dependencyFactory)),
                [(string) $habitEntryVersion],
            );
        } finally {
            // A real run is a separate process in production: boot a fresh kernel, so the migration objects are fresh too.
            self::ensureKernelShutdown();
            self::bootKernel();
            $freshFactory = $this->dependencyFactory();
            $upPlan = $freshFactory->getMigrationPlanCalculator()->getPlanUntilVersion($newestVersion);
            if ([] !== $upPlan->getItems()) {
                $freshFactory->getMigrator()->migrate($upPlan, new MigratorConfiguration());
            }
        }

        self::assertFalse($existsAfterDryRun, 'a dry run must not create the habit_entry table');
        self::assertFalse($recordedAfterDryRun, 'a dry run must not record the version');
        self::assertTrue($this->tableExists(), 'the real run after the dry run must create the table');
        self::assertSame(['chk_habit_entry_value'], $this->checkConstraintNames());
    }

    /**
     * Same restore-it-yourself pattern as HabitSchemaTest: the newest version is restored in `finally`, so
     * later migrations are restored too.
     */
    public function testDownThenUpRemovesAndRestoresTheTableWithItsIndexesAndCheck(): void
    {
        $dependencyFactory = $this->dependencyFactory();
        [$versionBefore, $newestVersion] = $this->versionsAround($dependencyFactory);

        // The plans must be computed one after the other: what a plan contains
        // depends on which versions are recorded as executed at that moment.
        $downPlan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($versionBefore);

        try {
            $dependencyFactory->getMigrator()->migrate($downPlan, new MigratorConfiguration());
            $tableExistsAfterDown = $this->tableExists();
            $habitTableExistsAfterDown = $this->connection()->createSchemaManager()->tablesExist(['habit']);
        } finally {
            $upPlan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($newestVersion);
            $dependencyFactory->getMigrator()->migrate($upPlan, new MigratorConfiguration());
        }

        self::assertFalse($tableExistsAfterDown, 'down() must drop the habit_entry table');
        self::assertTrue($habitTableExistsAfterDown, 'down() must leave the habit table alone');
        self::assertTrue($this->tableExists(), 'the migration must be applicable again after down()');
        self::assertSame(['chk_habit_entry_value'], $this->checkConstraintNames(), 'up() must recreate the check constraint');
        self::assertStringContainsString('(habit_id, entry_date)', $this->indexDefinition('uniq_habit_entry_habit_date'));
        self::assertStringContainsString('(entry_date DESC)', $this->indexDefinition('idx_habit_entry_date'));
        self::assertSame(1, $this->connection()->fetchOne("SELECT count(*) FROM pg_constraint WHERE conrelid = 'habit_entry'::regclass AND contype = 'f'"), 'up() must recreate the foreign key');
    }

    /**
     * @return array{Version, Version, Version} the version before the habit_entry migration, the newest one, and the habit_entry one
     */
    private function versionsAround(DependencyFactory $dependencyFactory): array
    {
        $allMigrations = $dependencyFactory->getMigrationPlanCalculator()->getMigrations()->getItems();
        usort(
            $allMigrations,
            static fn (AvailableMigration $a, AvailableMigration $b): int => (string) $a->getVersion() <=> (string) $b->getVersion(),
        );

        $indexes = [];
        foreach ($allMigrations as $index => $migration) {
            if (self::MIGRATION_DESCRIPTION === $migration->getMigration()->getDescription()) {
                $indexes[] = $index;
            }
        }
        self::assertCount(1, $indexes, \sprintf('expected exactly one migration described as "%s"', self::MIGRATION_DESCRIPTION));
        self::assertGreaterThan(0, $indexes[0], 'a migration must precede the habit_entry migration to migrate down to');

        return [$allMigrations[$indexes[0] - 1]->getVersion(), $allMigrations[\count($allMigrations) - 1]->getVersion(), $allMigrations[$indexes[0]]->getVersion()];
    }

    private function versionTable(DependencyFactory $dependencyFactory): string
    {
        $configuration = $dependencyFactory->getConfiguration()->getMetadataStorageConfiguration();
        self::assertInstanceOf(TableMetadataStorageConfiguration::class, $configuration);

        return $configuration->getTableName();
    }

    private function dependencyFactory(): DependencyFactory
    {
        $dependencyFactory = static::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $dependencyFactory);

        return $dependencyFactory;
    }

    /**
     * Inserts one habit row by raw SQL and returns its id.
     */
    private function insertHabit(string $slug = 'schema-test'): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection()->executeStatement(
            "INSERT INTO habit (id, slug, name, value_type, scale_min, scale_max, sort_order) VALUES (?, ?, 'Schematest', 'scale', 1, 5, 10)",
            [$id, $slug],
        );

        return $id;
    }

    /**
     * Inserts one entry row by raw SQL (Doctrine cannot get past a check constraint through the entity).
     * Defaults form a valid numeric row for 2026-09-08.
     *
     * @param array<string, string|bool|null> $overrides column => value
     */
    private function insertEntry(array $overrides): void
    {
        $values = $overrides + [
            'id' => Uuid::v7()->toRfc4122(),
            'entry_date' => '2026-09-08',
            'value_numeric' => '3.00',
            'value_bool' => null,
            'note' => null,
            'created_at' => '2026-09-08 19:04:11+00',
        ];

        // PDO would send a PHP `false` as an empty string, which Postgres rejects for a boolean column.
        $values = array_map(static fn (string|bool|null $value): ?string => \is_bool($value) ? ($value ? 'true' : 'false') : $value, $values);

        $columns = array_keys($values);
        $this->connection()->executeStatement(
            \sprintf(
                'INSERT INTO habit_entry (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_fill(0, \count($columns), '?')),
            ),
            array_values($values),
        );
    }

    private function tableExists(): bool
    {
        return $this->connection()->createSchemaManager()->tablesExist(['habit_entry']);
    }

    private function indexDefinition(string $indexName): string
    {
        $definition = $this->connection()->fetchOne(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            ['habit_entry', $indexName],
        );
        self::assertIsString($definition, \sprintf('index %s must exist on habit_entry', $indexName));

        return $definition;
    }

    /**
     * @return list<string>
     */
    private function checkConstraintNames(): array
    {
        $names = $this->connection()->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'habit_entry'::regclass AND contype = 'c' ORDER BY conname",
        );

        return array_map(
            static fn (mixed $name): string => \is_string($name) ? $name : self::fail('Expected a constraint name string.'),
            $names,
        );
    }

    private function connection(): Connection
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }
}
