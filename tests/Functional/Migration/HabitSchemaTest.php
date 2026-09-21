<?php

declare(strict_types=1);

namespace App\Tests\Functional\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Verifies the `habit` schema migration (ticket criteria 1 and 2): table,
 * columns, indexes, the four hand-written check constraints, and the
 * down-then-up round trip. Doctrine does not know check constraints (design.md
 * §2), so the only way to prove them is a raw INSERT that the database must
 * refuse - the same pattern as BodyWeightConstraintTest.
 *
 * Each constraint violation gets its own test method (data provider): after
 * the first failed statement Postgres aborts the transaction ("current
 * transaction is aborted"), so a second INSERT in the same test would fail for
 * the wrong reason. Each case violates exactly ONE constraint, and the
 * assertion looks for that constraint's name in the message: DBAL 4 has no
 * exception subtype for a CHECK violation, so a bare
 * `expectException(DriverException::class)` would already pass while the table
 * does not exist yet (`TableNotFoundException` is a DriverException too).
 *
 * Red reason before the migration exists: the `habit` table is missing.
 */
final class HabitSchemaTest extends KernelTestCase
{
    private const string MIGRATION_DESCRIPTION = 'Create habit table with its indexes and check constraints';

    private const array EXPECTED_COLUMNS = [
        'id' => ['uuid', 'NO'],
        'slug' => ['text', 'NO'],
        'name' => ['text', 'NO'],
        'value_type' => ['text', 'NO'],
        'unit' => ['text', 'YES'],
        'scale_min' => ['smallint', 'YES'],
        'scale_max' => ['smallint', 'YES'],
        'target_direction' => ['text', 'YES'],
        'target_value' => ['numeric', 'YES'],
        'sort_order' => ['smallint', 'NO'],
        'is_active' => ['boolean', 'NO'],
    ];

    private const array EXPECTED_CHECKS = [
        'chk_habit_duration_unit',
        'chk_habit_scale',
        'chk_habit_sort_order',
        'chk_habit_target',
    ];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testItCreatesTheHabitTableWithAllElevenColumnsTheirTypesAndNullability(): void
    {
        // Criterion 1.
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY column_name',
            ['habit'],
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

        $expected = self::EXPECTED_COLUMNS;
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    public function testItStoresTheTargetValueAsNumericEightTwo(): void
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'habit' AND column_name = 'target_value'",
        );

        self::assertSame(['numeric_precision' => 8, 'numeric_scale' => 2], $row);
    }

    public function testItDefinesTheUniqueIndexOnSlug(): void
    {
        // Criterion 1.
        $definition = $this->indexDefinition('uniq_habit_slug');

        self::assertStringContainsString('CREATE UNIQUE INDEX', $definition);
        self::assertStringContainsString('(slug)', $definition);
    }

    public function testItDefinesTheIndexOnActiveAndSortOrder(): void
    {
        $definition = $this->indexDefinition('idx_habit_active_sort');

        self::assertStringNotContainsString('UNIQUE', $definition);
        self::assertStringContainsString('(is_active, sort_order)', $definition);
    }

    public function testItDefinesExactlyTheFourNamedCheckConstraints(): void
    {
        // Criterion 1.
        self::assertSame(self::EXPECTED_CHECKS, $this->checkConstraintNames());
    }

    /**
     * @param array<string, string|int|null> $overrides
     */
    #[DataProvider('rowsViolatingTheScaleCheck')]
    #[DataProvider('rowsViolatingTheDurationUnitCheck')]
    #[DataProvider('rowsViolatingTheTargetCheck')]
    #[DataProvider('rowsViolatingTheSortOrderCheck')]
    public function testItRejectsARowThatViolatesACheckConstraint(string $constraint, array $overrides): void
    {
        try {
            $this->insertHabit($overrides);
            self::fail(\sprintf('expected %s to reject the row', $constraint));
        } catch (DriverException $exception) {
            self::assertStringContainsString($constraint, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string, array<string, string|int|null>}>
     */
    public static function rowsViolatingTheScaleCheck(): array
    {
        $constraint = 'chk_habit_scale';

        return [
            'scale without bounds' => [$constraint, ['scale_min' => null, 'scale_max' => null]],
            'scale with only a minimum' => [$constraint, ['scale_min' => 1, 'scale_max' => null]],
            'scale with only a maximum' => [$constraint, ['scale_min' => null, 'scale_max' => 5]],
            'scale with equal bounds' => [$constraint, ['scale_min' => 3, 'scale_max' => 3]],
            'scale with inverted bounds' => [$constraint, ['scale_min' => 5, 'scale_max' => 1]],
            'boolean with bounds' => [$constraint, ['value_type' => 'boolean']],
            'number with only a minimum' => [$constraint, ['value_type' => 'number', 'scale_min' => 1, 'scale_max' => null]],
            'duration with bounds' => [$constraint, ['value_type' => 'duration', 'unit' => 'h']],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string|int|null>}>
     */
    public static function rowsViolatingTheDurationUnitCheck(): array
    {
        return [
            'duration without a unit' => ['chk_habit_duration_unit', ['value_type' => 'duration', 'scale_min' => null, 'scale_max' => null, 'unit' => null]],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string|int|null>}>
     */
    public static function rowsViolatingTheTargetCheck(): array
    {
        return [
            'target value without a direction' => ['chk_habit_target', ['target_value' => '8.00', 'target_direction' => null]],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string|int|null>}>
     */
    public static function rowsViolatingTheSortOrderCheck(): array
    {
        return [
            'negative sort order' => ['chk_habit_sort_order', ['sort_order' => -10]],
        ];
    }

    public function testItRejectsASecondRowWithTheSameSlug(): void
    {
        $this->insertHabit(['slug' => 'duplicate-slug']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_habit_slug');

        $this->insertHabit(['slug' => 'duplicate-slug', 'sort_order' => 20]);
    }

    /**
     * @param array<string, string|int|null> $overrides
     */
    #[DataProvider('validRows')]
    public function testItAcceptsAValidRow(array $overrides): void
    {
        $this->insertHabit($overrides + ['slug' => 'valid-row']);

        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM habit WHERE slug = ?', ['valid-row']));
    }

    /**
     * @return array<string, array{array<string, string|int|null>}>
     */
    public static function validRows(): array
    {
        return [
            'zero-to-ten scale' => [['value_type' => 'scale', 'scale_min' => 0, 'scale_max' => 10]],
            'one-to-five scale with direction' => [['scale_min' => 1, 'scale_max' => 5, 'target_direction' => 'niedrig']],
            'boolean without bounds' => [['value_type' => 'boolean', 'scale_min' => null, 'scale_max' => null]],
            'duration with unit and target' => [['value_type' => 'duration', 'scale_min' => null, 'scale_max' => null, 'unit' => 'h', 'target_direction' => 'hoch', 'target_value' => '8.00']],
            'number with unit' => [['value_type' => 'number', 'scale_min' => null, 'scale_max' => null, 'unit' => 'Glas']],
            'direction without a target value' => [['target_direction' => 'hoch']],
            'sort order zero' => [['sort_order' => 0]],
        ];
    }

    public function testItDefaultsIsActiveToTrueWhenTheColumnIsOmitted(): void
    {
        $this->insertHabit(['slug' => 'default-active']);

        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM habit WHERE slug = ? AND is_active = true', ['default-active']));
    }

    public function testItKeepsTheEightDecimalTargetAsAnExactDecimal(): void
    {
        $this->insertHabit(['slug' => 'sleep-duration', 'value_type' => 'duration', 'scale_min' => null, 'scale_max' => null, 'unit' => 'h', 'target_direction' => 'hoch', 'target_value' => '8.00']);

        self::assertSame('8.00', $this->connection()->fetchOne('SELECT target_value FROM habit WHERE slug = ?', ['sleep-duration']));
    }

    /**
     * Criterion 2. Deliberately runs outside dama's per-test rollback, like
     * ExerciseMigrationTest (the migrator uses its own connection handling):
     * the test restores the schema itself by migrating back up to the newest
     * version - not merely to the habit migration, so later migrations (for
     * example the habit_entry table of T-0402) are restored as well. The
     * migration is found by its description, the same convention
     * ExerciseMigrationTest relies on; the description therefore must not
     * contain the word "exercise".
     *
     * Red reason before the implementation: no migration with that
     * description exists yet (the assertCount(1, ...) below fails). It can
     * only go green with `executeStatement()` in `up()`/`down()` instead of
     * `addSql()` - a second run of the same migration object would throw
     * FrozenMigration otherwise (design.md §2).
     */
    public function testDownThenUpRemovesAndRestoresTheHabitTableWithItsConstraints(): void
    {
        $dependencyFactory = static::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $dependencyFactory);
        self::assertTrue($this->habitTableExists(), 'the migration must be applied before this test runs');

        $allMigrations = $dependencyFactory->getMigrationPlanCalculator()->getMigrations()->getItems();
        usort(
            $allMigrations,
            static fn (AvailableMigration $a, AvailableMigration $b): int => (string) $a->getVersion() <=> (string) $b->getVersion(),
        );

        $habitIndexes = [];
        foreach ($allMigrations as $index => $migration) {
            if (self::MIGRATION_DESCRIPTION === $migration->getMigration()->getDescription()) {
                $habitIndexes[] = $index;
            }
        }
        self::assertCount(1, $habitIndexes, \sprintf('expected exactly one migration described as "%s"', self::MIGRATION_DESCRIPTION));
        $habitIndex = $habitIndexes[0];
        self::assertGreaterThan(0, $habitIndex, 'a migration must precede the habit migration to migrate down to');

        $versionBeforeHabit = $allMigrations[$habitIndex - 1]->getVersion();
        $newestVersion = $allMigrations[\count($allMigrations) - 1]->getVersion();

        // The plans must be computed one after the other: what a plan contains
        // depends on which versions are recorded as executed at that moment.
        $downPlan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($versionBeforeHabit);

        try {
            $dependencyFactory->getMigrator()->migrate($downPlan, new MigratorConfiguration());
            $tableExistsAfterDown = $this->habitTableExists();
        } finally {
            // The suite depends on the schema being back: restoring it is part of what is tested.
            $upPlan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($newestVersion);
            $dependencyFactory->getMigrator()->migrate($upPlan, new MigratorConfiguration());
        }

        self::assertFalse($tableExistsAfterDown, 'down() must drop the habit table');
        self::assertTrue($this->habitTableExists(), 'the migration must be applicable again after down()');
        self::assertSame(self::EXPECTED_CHECKS, $this->checkConstraintNames(), 'up() must recreate all four check constraints');
        self::assertStringContainsString('(slug)', $this->indexDefinition('uniq_habit_slug'));
        self::assertStringContainsString('(is_active, sort_order)', $this->indexDefinition('idx_habit_active_sort'));
    }

    /**
     * Inserts one habit row by raw SQL (Doctrine cannot get past a check
     * constraint through the entity). Defaults form a valid 1-5 scale row.
     *
     * @param array<string, string|int|null> $overrides column => value; `is_active` is left to the column default
     */
    private function insertHabit(array $overrides = []): void
    {
        $values = $overrides + [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => 'schema-test',
            'name' => 'Schematest',
            'value_type' => 'scale',
            'unit' => null,
            'scale_min' => 1,
            'scale_max' => 5,
            'target_direction' => null,
            'target_value' => null,
            'sort_order' => 10,
        ];

        $columns = array_keys($values);
        $this->connection()->executeStatement(
            \sprintf(
                'INSERT INTO habit (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_fill(0, \count($columns), '?')),
            ),
            array_values($values),
        );
    }

    private function habitTableExists(): bool
    {
        return $this->connection()->createSchemaManager()->tablesExist(['habit']);
    }

    private function indexDefinition(string $indexName): string
    {
        $definition = $this->connection()->fetchOne(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            ['habit', $indexName],
        );
        self::assertIsString($definition, \sprintf('index %s must exist on habit', $indexName));

        return $definition;
    }

    /**
     * @return list<string>
     */
    private function checkConstraintNames(): array
    {
        $names = $this->connection()->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'habit'::regclass AND contype = 'c' ORDER BY conname",
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
