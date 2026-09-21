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
use App\Tests\Factory\HabitEntryFactory;
use App\Tests\Factory\HabitFactory;
use App\Tests\Unit\Service\Habit\InMemoryHabitDefinitionProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test). The command
 * side of the sync guard (T-0402 design.md §4.5, criteria 21-26): a habit that
 * already has entries keeps its value type, unit and scale, and the sync
 * refuses instead of devaluing the history. The unit-level cases live in
 * HabitCatalogSyncGuardTest; this file proves the wiring with the real
 * repository query behind `findSlugsWithEntries()` and the exit code and
 * output of `app:habits:sync`.
 *
 * The habit table is inspected through DBAL, never through the entity
 * manager (T-0401 lesson: the identity map can make a wrong implementation
 * look correct). Every test empties the habit table first.
 */
final class HabitsSyncGuardCommandTest extends KernelTestCase
{
    use Factories;

    /**
     * The message points to a new slug; design.md §4.5 words it "neuem Slug" in the text but names the
     * test contract "neuen Slug", so both grammatical forms are accepted.
     */
    private const string NEW_SLUG_HINT = '/neue[mn] Slug/';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager()->createQuery('DELETE FROM App\Entity\Habit h')->execute();
    }

    public function testItRefusesAChangedValueTypeForAHabitWithEntriesAndWritesNothing(): void
    {
        // Criterion 21: exit code, slug and "neuen Slug" in the output, nothing changed, the new habit not created.
        $this->habitWithEntry('mood');
        $rowsBefore = $this->snapshot();
        $definitions = [
            new HabitDefinition(slug: 'mood', name: 'Testgewohnheit', valueType: HabitValueType::Boolean, sortOrder: 10, targetDirection: HabitTargetDirection::High),
            new HabitDefinition(slug: 'brand-new', name: 'Neu', valueType: HabitValueType::Boolean, sortOrder: 20),
        ];

        $commandTester = $this->runSync($definitions);

        self::assertNotSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('mood', $commandTester->getDisplay());
        self::assertMatchesRegularExpression(self::NEW_SLUG_HINT, $this->flatten($commandTester->getDisplay()));
        self::assertSame($rowsBefore, $this->snapshot(), 'nothing may be written, not even the new habit');
    }

    /**
     * @param array<string, mixed> $existing overrides for the stored habit
     */
    #[DataProvider('guardedChanges')]
    public function testItRefusesEachOtherGuardedChangeForAHabitWithEntries(array $existing, HabitDefinition $changed): void
    {
        // Criterion 22: scale minimum, scale maximum and unit, one case each.
        $habit = HabitFactory::createOne($existing);
        HabitEntryFactory::createOne(['habit' => $habit]);
        $rowsBefore = $this->snapshot();

        $commandTester = $this->runSync([$changed]);

        self::assertNotSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString($habit->getSlug(), $commandTester->getDisplay());
        self::assertMatchesRegularExpression(self::NEW_SLUG_HINT, $this->flatten($commandTester->getDisplay()));
        self::assertSame($rowsBefore, $this->snapshot());
    }

    /**
     * @return array<string, array{array<string, mixed>, HabitDefinition}>
     */
    public static function guardedChanges(): array
    {
        $scale = ['slug' => 'mood', 'name' => 'Testgewohnheit', 'valueType' => HabitValueType::Scale, 'scaleMin' => 1, 'scaleMax' => 5, 'sortOrder' => 10, 'targetDirection' => HabitTargetDirection::High];
        $duration = ['slug' => 'sleep-duration', 'name' => 'Testgewohnheit', 'valueType' => HabitValueType::Duration, 'unit' => 'h', 'scaleMin' => null, 'scaleMax' => null, 'sortOrder' => 10, 'targetDirection' => HabitTargetDirection::High];

        return [
            'scale minimum' => [$scale, new HabitDefinition(slug: 'mood', name: 'Testgewohnheit', valueType: HabitValueType::Scale, sortOrder: 10, scaleMin: 0, scaleMax: 5, targetDirection: HabitTargetDirection::High)],
            'scale maximum' => [$scale, new HabitDefinition(slug: 'mood', name: 'Testgewohnheit', valueType: HabitValueType::Scale, sortOrder: 10, scaleMin: 1, scaleMax: 10, targetDirection: HabitTargetDirection::High)],
            'unit' => [$duration, new HabitDefinition(slug: 'sleep-duration', name: 'Testgewohnheit', valueType: HabitValueType::Duration, sortOrder: 10, unit: 'min', targetDirection: HabitTargetDirection::High)],
        ];
    }

    public function testItRefusesOnADryRunToo(): void
    {
        // Criterion 25: the dry run predicts the abort, with the same message, and writes nothing.
        $this->habitWithEntry('mood');
        $rowsBefore = $this->snapshot();

        $commandTester = $this->runSync([$this->definition('mood', HabitValueType::Boolean)], dryRun: true);

        self::assertNotSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('mood', $commandTester->getDisplay());
        self::assertMatchesRegularExpression(self::NEW_SLUG_HINT, $this->flatten($commandTester->getDisplay()));
        self::assertSame($rowsBefore, $this->snapshot());
    }

    #[DataProvider('unguardedChanges')]
    public function testItSyncsAnUnguardedChangeOfAHabitWithEntries(HabitDefinition $definition, string $column, mixed $expected): void
    {
        // Criterion 23.
        $this->habitWithEntry('mood');

        $commandTester = $this->runSync([$definition]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('updated: 1', $commandTester->getDisplay());
        self::assertSame($expected, $this->connection()->fetchOne(\sprintf('SELECT %s FROM habit WHERE slug = ?', $column), ['mood']));
        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM habit_entry'), 'the entries stay');
    }

    /**
     * @return array<string, array{HabitDefinition, string, mixed}>
     */
    public static function unguardedChanges(): array
    {
        return [
            'name' => [self::moodDefinition(name: 'Neuer Name'), 'name', 'Neuer Name'],
            'target direction' => [self::moodDefinition(targetDirection: HabitTargetDirection::Low), 'target_direction', 'niedrig'],
            'target value' => [self::moodDefinition(targetValue: 4.0), 'target_value', '4.00'],
            'sort order' => [self::moodDefinition(sortOrder: 99), 'sort_order', 99],
        ];
    }

    /**
     * The scale habit "mood" as `habitWithEntry()` stores it, with one field changed.
     */
    private static function moodDefinition(
        string $name = 'Testgewohnheit',
        HabitTargetDirection $targetDirection = HabitTargetDirection::High,
        ?float $targetValue = null,
        int $sortOrder = 10,
    ): HabitDefinition {
        return new HabitDefinition(slug: 'mood', name: $name, valueType: HabitValueType::Scale, sortOrder: $sortOrder, scaleMin: 1, scaleMax: 5, targetDirection: $targetDirection, targetValue: $targetValue);
    }

    /**
     * @param array<string, mixed> $existing overrides for the stored habit
     */
    #[DataProvider('guardedChanges')]
    public function testItSyncsAnyGuardedChangeOfAHabitWithoutEntries(array $existing, HabitDefinition $changed): void
    {
        // Criterion 24.
        HabitFactory::createOne($existing);

        $commandTester = $this->runSync([$changed]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('updated: 1', $commandTester->getDisplay());
    }

    public function testItSyncsAChangedValueTypeOfAHabitWithoutEntries(): void
    {
        // Criterion 24, the fourth guarded field.
        HabitFactory::createOne(['slug' => 'mood', 'name' => 'Testgewohnheit', 'sortOrder' => 10, 'targetDirection' => HabitTargetDirection::High]);

        $commandTester = $this->runSync([$this->definition('mood', HabitValueType::Boolean)]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertSame('boolean', $this->connection()->fetchOne('SELECT value_type FROM habit WHERE slug = ?', ['mood']));
    }

    public function testItSyncsAGuardedChangeWhenOnlyAnotherHabitHasEntries(): void
    {
        // Criterion 24: the entries of another habit are no reason to refuse.
        HabitFactory::createOne(['slug' => 'mood', 'name' => 'Testgewohnheit', 'sortOrder' => 10, 'targetDirection' => HabitTargetDirection::High]);
        $this->habitWithEntry('sleep-quality', sortOrder: 20);
        $definitions = [
            $this->definition('mood', HabitValueType::Boolean),
            new HabitDefinition(slug: 'sleep-quality', name: 'Testgewohnheit', valueType: HabitValueType::Scale, sortOrder: 20, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High),
        ];

        $commandTester = $this->runSync($definitions);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertSame('boolean', $this->connection()->fetchOne('SELECT value_type FROM habit WHERE slug = ?', ['mood']));
    }

    public function testItDeactivatesAHabitWithEntriesThatLeftTheCatalogAndKeepsItsEntries(): void
    {
        // Criterion 26.
        $this->habitWithEntry('retired', sortOrder: 5);

        $commandTester = $this->runSync([$this->definition('mood', HabitValueType::Scale, sortOrder: 10)]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('deactivated: 1', $commandTester->getDisplay());
        self::assertSame(0, $this->connection()->fetchOne("SELECT count(*) FROM habit WHERE slug = 'retired' AND is_active = true"));
        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM habit_entry'), 'the entries of a deactivated habit stay');
    }

    public function testItReactivatesAnInactiveHabitWithEntriesWhoseSlugIsBackAndKeepsItsEntries(): void
    {
        // Criterion 26.
        $habit = HabitFactory::createOne(['slug' => 'mood', 'name' => 'Testgewohnheit', 'sortOrder' => 10, 'targetDirection' => HabitTargetDirection::High, 'isActive' => false]);
        HabitEntryFactory::createOne(['habit' => $habit]);

        $commandTester = $this->runSync([$this->definition('mood', HabitValueType::Scale)]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('updated: 1', $commandTester->getDisplay());
        self::assertSame(1, $this->connection()->fetchOne("SELECT count(*) FROM habit WHERE slug = 'mood' AND is_active = true"));
        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM habit_entry'));
    }

    /**
     * The console output wraps long lines inside its error box; joining the lines makes phrases searchable.
     */
    private function flatten(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    private function habitWithEntry(string $slug, int $sortOrder = 10): void
    {
        $habit = HabitFactory::createOne(['slug' => $slug, 'name' => 'Testgewohnheit', 'sortOrder' => $sortOrder, 'targetDirection' => HabitTargetDirection::High]);
        HabitEntryFactory::createOne(['habit' => $habit]);
    }

    private function definition(string $slug, HabitValueType $type, int $sortOrder = 10): HabitDefinition
    {
        return new HabitDefinition(
            slug: $slug,
            name: 'Testgewohnheit',
            valueType: $type,
            sortOrder: $sortOrder,
            scaleMin: HabitValueType::Scale === $type ? 1 : null,
            scaleMax: HabitValueType::Scale === $type ? 5 : null,
            targetDirection: HabitTargetDirection::High,
        );
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
     * @return list<array<string, mixed>> every column of every row, ordered, for before/after comparisons
     */
    private function snapshot(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT * FROM habit ORDER BY slug');
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
