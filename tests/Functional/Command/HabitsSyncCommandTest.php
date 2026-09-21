<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\HabitsSyncCommand;
use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Repository\HabitRepository;
use App\Service\Habit\HabitCatalogSynchronizer;
use App\Service\Habit\HabitDefinition;
use App\Service\Habit\HabitDefinitionValidator;
use App\Tests\Factory\HabitFactory;
use App\Tests\Unit\Service\Habit\InMemoryHabitDefinitionProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test). Exercises
 * `app:habits:sync` end to end (design.md §4.4): ticket criteria 3-6 plus the
 * command-level side of criteria 12-13.
 *
 * Only the wiring test runs the real `HabitCatalog`. Every other test builds
 * the command itself around a test-owned definition list
 * (InMemoryHabitDefinitionProvider) and the real HabitRepository, so the
 * scenarios do not depend on the R-04 catalog content (same idea as
 * SyncExercisesCommandTest::runSync()).
 *
 * The database is inspected through DBAL, never through the repository
 * without `clear()`: after a dry run the entity manager's identity map could
 * still hold mutated-but-unflushed objects and make a wrong implementation
 * look correct (design.md §10/A11). The one test that reads through the
 * repository does so on purpose to prove that the identity map is clean.
 *
 * Every test empties the habit table first, because the catalog rows are a
 * deployment concern and must not leak into these scenarios.
 */
final class HabitsSyncCommandTest extends KernelTestCase
{
    use Factories;

    private const array REAL_CATALOG_SLUGS = [
        'knee-pain',
        'mobility-stretch',
        'mood',
        'recovery-readiness',
        'sleep-duration',
        'sleep-quality',
        'stress',
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager()->createQuery('DELETE FROM App\Entity\Habit h')->execute();
    }

    public function testItIsRegisteredAsAppHabitsSync(): void
    {
        $application = new Application(self::$kernel ?? self::fail('the kernel is not booted'));

        $command = $application->find('app:habits:sync');

        // find() hands out a lazy proxy, so the name and the definition are what can be checked.
        self::assertSame('app:habits:sync', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('dry-run'));
    }

    public function testItCreatesTheSevenCatalogRowsOfTheRealCatalogInAnEmptyTable(): void
    {
        // Criterion 3, wired through the container against the real HabitCatalog.
        $application = new Application(self::$kernel ?? self::fail('the kernel is not booted'));
        $commandTester = new CommandTester($application->find('app:habits:sync'));

        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('created: 7', $commandTester->getDisplay());
        self::assertSame(7, $this->habitCount());
        self::assertSame(self::REAL_CATALOG_SLUGS, $this->slugsInDatabase());
    }

    public function testItCreatesOneRowPerDefinitionAndReportsTheCountUnderCreated(): void
    {
        // Criterion 3.
        $commandTester = $this->runSync($this->sevenDefinitions());

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('created: 7, updated: 0, deactivated: 0, unchanged: 0', $commandTester->getDisplay());
        self::assertSame(7, $this->habitCount());
    }

    public function testItStoresTheDefinitionValuesInTheRow(): void
    {
        $this->runSync($this->sevenDefinitions());

        $row = $this->connection()->fetchAssociative(
            'SELECT name, value_type, unit, scale_min, scale_max, target_direction, target_value, sort_order, is_active FROM habit WHERE slug = ?',
            ['sleep-duration'],
        );

        self::assertSame(
            [
                'name' => 'Schlafdauer',
                'value_type' => 'duration',
                'unit' => 'h',
                'scale_min' => null,
                'scale_max' => null,
                'target_direction' => 'hoch',
                'target_value' => '8.00',
                'sort_order' => 20,
                'is_active' => true,
            ],
            $row,
        );
    }

    public function testItChangesNoRowAndReportsEveryDefinitionAsUnchangedOnASecondRun(): void
    {
        // Criterion 4.
        $definitions = $this->sevenDefinitions();
        $this->runSync($definitions);
        $rowsAfterFirstRun = $this->snapshot();

        $commandTester = $this->runSync($definitions);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('created: 0, updated: 0, deactivated: 0, unchanged: 7', $commandTester->getDisplay());
        self::assertSame($rowsAfterFirstRun, $this->snapshot(), 'a repeated run must not change any row');
    }

    public function testItKeepsEveryIdOnASecondRun(): void
    {
        // Criterion 4: ids stay, no delete-and-insert.
        $definitions = $this->sevenDefinitions();
        $this->runSync($definitions);
        $idsAfterFirstRun = $this->idsBySlug();
        self::assertCount(7, $idsAfterFirstRun);

        $this->runSync($definitions);

        self::assertSame($idsAfterFirstRun, $this->idsBySlug());
    }

    public function testItUpdatesAChangedRowInPlaceAndKeepsItsId(): void
    {
        $definitions = $this->sevenDefinitions();
        $this->runSync($definitions);
        $idBefore = $this->idsBySlug()['mood'] ?? null;

        $changed = array_map(
            static fn (HabitDefinition $definition): HabitDefinition => 'mood' === $definition->slug
                ? new HabitDefinition(slug: 'mood', name: 'Laune', valueType: HabitValueType::Scale, sortOrder: 55, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High)
                : $definition,
            $definitions,
        );
        $commandTester = $this->runSync($changed);

        self::assertStringContainsString('created: 0, updated: 1, deactivated: 0, unchanged: 6', $commandTester->getDisplay());
        self::assertSame('Laune', $this->connection()->fetchOne('SELECT name FROM habit WHERE slug = ?', ['mood']));
        self::assertSame(55, $this->connection()->fetchOne('SELECT sort_order FROM habit WHERE slug = ?', ['mood']));
        self::assertSame($idBefore, $this->idsBySlug()['mood'] ?? null);
    }

    public function testItDeactivatesAHabitThatIsNoLongerDefinedAndKeepsItsRow(): void
    {
        // Criterion 5.
        HabitFactory::createOne(['slug' => 'retired-habit', 'name' => 'Nicht mehr gefuehrt']);
        $idBefore = $this->idsBySlug()['retired-habit'] ?? null;
        self::assertNotNull($idBefore);

        $commandTester = $this->runSync($this->sevenDefinitions());

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('created: 7, updated: 0, deactivated: 1, unchanged: 0', $commandTester->getDisplay());
        self::assertSame(8, $this->habitCount(), 'the orphan row must stay, sync never deletes');
        self::assertSame(1, $this->countWhere("slug = 'retired-habit' AND is_active = false"));
        self::assertSame($idBefore, $this->idsBySlug()['retired-habit'] ?? null);
        self::assertSame('Nicht mehr gefuehrt', $this->connection()->fetchOne('SELECT name FROM habit WHERE slug = ?', ['retired-habit']), 'only the flag changes');
    }

    public function testItLeavesAnAlreadyInactiveOrphanAloneOnTheNextRun(): void
    {
        HabitFactory::createOne(['slug' => 'retired-habit']);
        $definitions = $this->sevenDefinitions();
        $this->runSync($definitions);

        $commandTester = $this->runSync($definitions);

        self::assertStringContainsString('created: 0, updated: 0, deactivated: 0, unchanged: 7', $commandTester->getDisplay());
        self::assertSame(1, $this->countWhere("slug = 'retired-habit' AND is_active = false"));
    }

    public function testItReactivatesAnInactiveRowWhoseSlugIsDefinedAgain(): void
    {
        $definition = new HabitDefinition(slug: 'mood', name: 'Stimmung', valueType: HabitValueType::Scale, sortOrder: 50, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High);
        HabitFactory::createOne(['slug' => 'mood', 'name' => 'Stimmung', 'sortOrder' => 50, 'targetDirection' => HabitTargetDirection::High, 'isActive' => false]);

        $commandTester = $this->runSync([$definition]);

        self::assertStringContainsString('created: 0, updated: 1, deactivated: 0, unchanged: 0', $commandTester->getDisplay());
        self::assertSame(1, $this->countWhere("slug = 'mood' AND is_active = true"));
    }

    public function testItWritesNothingToTheDatabaseOnADryRun(): void
    {
        // Criterion 6: one row to update, one to deactivate, one to create.
        $this->runSync($this->sevenDefinitions());
        $rowsBefore = $this->snapshot();

        $this->runSync($this->plannedChangesDefinitions(), dryRun: true);

        self::assertSame($rowsBefore, $this->snapshot(), '--dry-run must not change a single row');
    }

    public function testItWritesNothingWhenADryRunFindsAnEmptyTable(): void
    {
        // Criterion 6.
        $commandTester = $this->runSync($this->sevenDefinitions(), dryRun: true);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertSame(0, $this->habitCount());
    }

    public function testItLeavesTheIdentityMapUntouchedOnADryRun(): void
    {
        // Criterion 6, the Doctrine trap: a dry run that mutates entities and
        // merely skips flush() would leave the changes visible to every later
        // read in the same process, although the database is unchanged.
        $this->runSync($this->sevenDefinitions());
        $this->entityManager()->clear();

        $this->runSync($this->plannedChangesDefinitions(), dryRun: true);

        $habits = $this->habitRepository()->findAllIndexedBySlug();
        self::assertSame(50, $habits['mood']->getSortOrder(), 'a planned update must not be visible in the identity map');
        self::assertTrue($habits['stress']->isActive(), 'a planned deactivation must not be visible in the identity map');
        self::assertArrayNotHasKey('new-habit', $habits);
    }

    public function testItPrintsTheDryRunNoticeAndTheChangeTable(): void
    {
        // Criterion 6: the change table is still printed.
        $this->runSync($this->sevenDefinitions());

        $commandTester = $this->runSync($this->plannedChangesDefinitions(), dryRun: true);

        $display = $commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('Trockenlauf', $display);
        self::assertStringContainsString('Slug', $display);
        self::assertStringContainsString('Aktion', $display);
        self::assertStringContainsString('Felder', $display);
        // SymfonyStyle tables are space-separated, one row per change: slug, action, fields.
        self::assertMatchesRegularExpression('/new-habit\s+created/', $display);
        self::assertMatchesRegularExpression('/mood\s+updated\s+sortOrder/', $display);
        self::assertMatchesRegularExpression('/stress\s+deactivated/', $display);
    }

    public function testItCountsTheDryRunPlanInTheSummaryLine(): void
    {
        $this->runSync($this->sevenDefinitions());

        $commandTester = $this->runSync($this->plannedChangesDefinitions(), dryRun: true);

        // 7 stored: 5 unchanged, mood updated, stress deactivated; plus new-habit created.
        self::assertStringContainsString('created: 1, updated: 1, deactivated: 1, unchanged: 5', $commandTester->getDisplay());
    }

    public function testItDoesNotPrintTheDryRunNoticeOnARealRun(): void
    {
        $commandTester = $this->runSync($this->sevenDefinitions());

        self::assertStringNotContainsString('Trockenlauf', $commandTester->getDisplay());
    }

    public function testItAbortsWithTheSlugAndWritesNothingForADurationWithoutAUnit(): void
    {
        // Criterion 12.
        HabitFactory::createOne(['slug' => 'baseline-habit']);
        $rowsBefore = $this->snapshot();
        $definitions = [
            new HabitDefinition(slug: 'sleep-duration', name: 'Schlafdauer', valueType: HabitValueType::Duration, sortOrder: 20),
        ];

        $commandTester = $this->runSync($definitions);

        self::assertNotSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('sleep-duration', $commandTester->getDisplay());
        self::assertSame($rowsBefore, $this->snapshot(), 'nothing may be written, the baseline row must not even be deactivated');
    }

    public function testItAbortsAndNamesTheSlugWhenTwoDefinitionsShareASlug(): void
    {
        // Criterion 13.
        HabitFactory::createOne(['slug' => 'baseline-habit']);
        $rowsBefore = $this->snapshot();
        $twice = new HabitDefinition(slug: 'duplicate-slug', name: 'Doppelt', valueType: HabitValueType::Boolean, sortOrder: 10);
        $again = new HabitDefinition(slug: 'duplicate-slug', name: 'Nochmal', valueType: HabitValueType::Boolean, sortOrder: 20);

        $commandTester = $this->runSync([$twice, $again]);

        self::assertNotSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('duplicate-slug', $commandTester->getDisplay());
        self::assertSame($rowsBefore, $this->snapshot(), 'nothing may be written');
    }

    public function testItPrintsAnErrorMessageForAnInvalidCatalogOnADryRunToo(): void
    {
        $definitions = [
            new HabitDefinition(slug: 'sleep-duration', name: 'Schlafdauer', valueType: HabitValueType::Duration, sortOrder: 20),
        ];

        $commandTester = $this->runSync($definitions, dryRun: true);

        self::assertNotSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('sleep-duration', $commandTester->getDisplay());
        self::assertSame(0, $this->habitCount());
    }

    /**
     * @param list<HabitDefinition> $definitions
     */
    private function runSync(array $definitions, bool $dryRun = false): CommandTester
    {
        $synchronizer = new HabitCatalogSynchronizer(
            new InMemoryHabitDefinitionProvider($definitions),
            $this->habitRepository(),
            new HabitDefinitionValidator(),
        );

        $commandTester = new CommandTester(new HabitsSyncCommand($synchronizer));
        $commandTester->execute($dryRun ? ['--dry-run' => true] : []);

        return $commandTester;
    }

    /**
     * Seven definitions with all four value types, independent of the real catalog.
     *
     * @return list<HabitDefinition>
     */
    private function sevenDefinitions(): array
    {
        return [
            new HabitDefinition(slug: 'knee-pain', name: 'Knieschmerz', valueType: HabitValueType::Scale, sortOrder: 10, scaleMin: 0, scaleMax: 10, targetDirection: HabitTargetDirection::Low),
            new HabitDefinition(slug: 'sleep-duration', name: 'Schlafdauer', valueType: HabitValueType::Duration, sortOrder: 20, unit: 'h', targetDirection: HabitTargetDirection::High, targetValue: 8.0),
            new HabitDefinition(slug: 'sleep-quality', name: 'Schlafqualitaet', valueType: HabitValueType::Scale, sortOrder: 30, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High),
            new HabitDefinition(slug: 'stress', name: 'Stress', valueType: HabitValueType::Scale, sortOrder: 40, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::Low),
            new HabitDefinition(slug: 'mood', name: 'Stimmung', valueType: HabitValueType::Scale, sortOrder: 50, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High),
            new HabitDefinition(slug: 'water', name: 'Wasser', valueType: HabitValueType::Number, sortOrder: 60, unit: 'Glas', targetDirection: HabitTargetDirection::High, targetValue: 8.0),
            new HabitDefinition(slug: 'mobility-stretch', name: 'Beweglichkeit', valueType: HabitValueType::Boolean, sortOrder: 70, targetDirection: HabitTargetDirection::High),
        ];
    }

    /**
     * Against sevenDefinitions() stored: `mood` moves to sort order 55
     * (updated), `stress` is gone (deactivated), `new-habit` is new (created).
     *
     * @return list<HabitDefinition>
     */
    private function plannedChangesDefinitions(): array
    {
        $definitions = [];
        foreach ($this->sevenDefinitions() as $definition) {
            if ('stress' === $definition->slug) {
                continue;
            }

            $definitions[] = 'mood' === $definition->slug
                ? new HabitDefinition(slug: 'mood', name: 'Stimmung', valueType: HabitValueType::Scale, sortOrder: 55, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High)
                : $definition;
        }

        $definitions[] = new HabitDefinition(slug: 'new-habit', name: 'Neu', valueType: HabitValueType::Boolean, sortOrder: 80);

        return $definitions;
    }

    /**
     * @return list<array<string, mixed>> every column of every row, ordered, for before/after comparisons
     */
    private function snapshot(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT * FROM habit ORDER BY slug');
    }

    /**
     * @return array<mixed, mixed> slug => id
     */
    private function idsBySlug(): array
    {
        return $this->connection()->fetchAllKeyValue('SELECT slug, id FROM habit ORDER BY slug');
    }

    /**
     * @return list<mixed>
     */
    private function slugsInDatabase(): array
    {
        return $this->connection()->fetchFirstColumn('SELECT slug FROM habit ORDER BY slug');
    }

    private function habitCount(): int
    {
        return $this->countWhere('true');
    }

    private function countWhere(string $condition): int
    {
        $count = $this->connection()->fetchOne('SELECT count(*) FROM habit WHERE '.$condition);
        self::assertIsInt($count);

        return $count;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function habitRepository(): HabitRepository
    {
        $repository = static::getContainer()->get(HabitRepository::class);
        self::assertInstanceOf(HabitRepository::class, $repository);

        return $repository;
    }
}
