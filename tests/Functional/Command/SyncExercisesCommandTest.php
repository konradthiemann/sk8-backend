<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\SyncExercisesCommand;
use App\Enum\Equipment;
use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;
use App\Repository\ExerciseRepository;
use App\Service\Training\ExerciseCatalogFile;
use App\Tests\Factory\ExerciseFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test, like every
 * other tests/Functional case - only ExerciseMigrationTest is the deliberate
 * exception). Exercises `app:exercise:sync` end to end (design.md §4/§5:
 * App\Command\SyncExercisesCommand, sequence diagram).
 *
 * Tester's resolution of the ticket's open question (design.md §4: "der Pfad
 * der Datei vermutlich ueber ein Konstruktor-Argument oder Kernel-Parameter
 * injizierbar; entscheide das selbst"): SyncExercisesCommand takes the
 * catalog file path as an explicit fourth constructor argument
 * (`string $catalogFilePath`), bound in production to
 * `%kernel.project_dir%/config/data/exercises.json` via a service parameter.
 * Every test here constructs the command directly (bypassing the
 * container's bound parameter) with its own temporary catalog file, so each
 * scenario is independent of both the real catalog's content and of the
 * other test methods. tests.md documents this assumption.
 *
 * Every test clears the exercise table first (`clearCatalog()`) for the same
 * reason ExercisesTest does: the real 16-row catalog's content is R-03,
 * still open (design.md §2, "Offene Punkte"), and the command's behaviour
 * must not depend on it.
 */
final class SyncExercisesCommandTest extends KernelTestCase
{
    use Factories;

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testItCreatesAnExerciseForEverySlugNotYetInTheDatabase(): void
    {
        $this->clearCatalog();
        $path = $this->writeCatalog([
            $this->minimalEntry('single-leg-balance'),
            $this->minimalEntry('ring-row'),
        ]);

        $exitCode = $this->runSync($path)->getStatusCode();

        self::assertSame(Command::SUCCESS, $exitCode);
        $bySlug = $this->exerciseRepository()->findAllIndexedBySlug();
        self::assertCount(2, $bySlug);
        self::assertArrayHasKey('single-leg-balance', $bySlug);
        self::assertArrayHasKey('ring-row', $bySlug);
    }

    public function testItReportsNoChangesAndKeepsTheSameIdWhenRunTwiceWithAnUnchangedCatalog(): void
    {
        // Criterion 7.
        $this->clearCatalog();
        $path = $this->writeCatalog([$this->minimalEntry('single-leg-balance')]);

        self::assertSame(Command::SUCCESS, $this->runSync($path)->getStatusCode());
        $idAfterFirstRun = $this->exerciseRepository()->findAllIndexedBySlug()['single-leg-balance']->getId();

        self::assertSame(Command::SUCCESS, $this->runSync($path)->getStatusCode());
        $bySlug = $this->exerciseRepository()->findAllIndexedBySlug();

        self::assertCount(1, $bySlug, 'a second run of an unchanged catalog must not create a duplicate row');
        self::assertTrue($idAfterFirstRun->equals($bySlug['single-leg-balance']->getId()), 'the existing row must keep its id');
    }

    public function testItUpdatesAnExistingRowsKneeLoadWithoutChangingItsId(): void
    {
        // Criterion 8.
        $this->clearCatalog();
        $firstEntry = $this->minimalEntry('single-leg-balance');
        $firstEntry['kneeLoad'] = 'niedrig';
        $this->runSync($this->writeCatalog([$firstEntry]));
        $idBefore = $this->exerciseRepository()->findAllIndexedBySlug()['single-leg-balance']->getId();

        $changedEntry = $this->minimalEntry('single-leg-balance');
        $changedEntry['kneeLoad'] = 'hoch';
        $exitCode = $this->runSync($this->writeCatalog([$changedEntry]))->getStatusCode();

        self::assertSame(Command::SUCCESS, $exitCode);
        $exercise = $this->exerciseRepository()->findAllIndexedBySlug()['single-leg-balance'];
        self::assertSame(KneeLoad::High, $exercise->getKneeLoad());
        self::assertTrue($idBefore->equals($exercise->getId()));
    }

    public function testItLeavesARowNotPresentInTheCatalogUntouchedAndReportsItAsNotInFile(): void
    {
        // Criterion 9.
        $this->clearCatalog();
        $orphan = ExerciseFactory::createOne(['slug' => 'orphan-exercise', 'kneeLoad' => KneeLoad::Medium]);
        $orphanId = $orphan->getId();
        $path = $this->writeCatalog([$this->minimalEntry('a-different-exercise')]);

        $commandTester = $this->runSync($path);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $bySlug = $this->exerciseRepository()->findAllIndexedBySlug();
        self::assertArrayHasKey('orphan-exercise', $bySlug, 'a row missing from the catalog file must not be deleted');
        self::assertTrue($orphanId->equals($bySlug['orphan-exercise']->getId()));
        self::assertSame(KneeLoad::Medium, $bySlug['orphan-exercise']->getKneeLoad(), 'a row missing from the catalog file must not be modified');
        self::assertStringContainsString('nicht in der Datei', $commandTester->getDisplay());
    }

    public function testItAbortsWithoutWritingWhenTheCatalogHasAnUnknownKneeLoadValue(): void
    {
        // Criterion 10.
        $this->clearCatalog();
        ExerciseFactory::createOne(['slug' => 'baseline-exercise']);
        $badEntry = $this->minimalEntry('bad-knee-load-exercise');
        $badEntry['kneeLoad'] = 'sehr-hoch';
        $path = $this->writeCatalog([$badEntry]);

        $commandTester = $this->runSync($path);

        self::assertNotSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertCount(1, $this->exerciseRepository()->findAllOrdered(), 'nothing may be written when the catalog fails validation');
        self::assertNotSame('', trim($commandTester->getDisplay()), 'a real error message must be printed');
    }

    public function testItAbortsAndNamesTheSlugWhenTheCatalogHasADuplicateSlug(): void
    {
        // Criterion 11.
        $this->clearCatalog();
        ExerciseFactory::createOne(['slug' => 'baseline-exercise']);
        $path = $this->writeCatalog([
            $this->minimalEntry('duplicate-slug'),
            $this->minimalEntry('duplicate-slug'),
        ]);

        $commandTester = $this->runSync($path);

        self::assertNotSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertCount(1, $this->exerciseRepository()->findAllOrdered(), 'nothing may be written when the catalog fails validation');
        self::assertStringContainsString('duplicate-slug', $commandTester->getDisplay());
    }

    public function testItReportsPlannedChangesWithoutWritingWhenDryRunIsSet(): void
    {
        // Criterion 12.
        $this->clearCatalog();
        $path = $this->writeCatalog([$this->minimalEntry('single-leg-balance')]);

        $commandTester = $this->runSync($path, dryRun: true);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertCount(0, $this->exerciseRepository()->findAllOrdered(), '--dry-run must never write');
        self::assertNotSame('', trim($commandTester->getDisplay()), 'a report must still be printed');
    }

    private function runSync(string $catalogPath, bool $dryRun = false): CommandTester
    {
        $exerciseRepository = $this->exerciseRepository();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $command = new SyncExercisesCommand(new ExerciseCatalogFile(), $catalogPath, $exerciseRepository, $entityManager);

        $commandTester = new CommandTester($command);
        $commandTester->execute($dryRun ? ['--dry-run' => true] : []);

        return $commandTester;
    }

    private function exerciseRepository(): ExerciseRepository
    {
        $repository = static::getContainer()->get(ExerciseRepository::class);
        self::assertInstanceOf(ExerciseRepository::class, $repository);

        return $repository;
    }

    private function clearCatalog(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->createQuery('DELETE FROM App\Entity\Exercise e')->execute();
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeCatalog(array $entries): string
    {
        $path = sys_get_temp_dir().'/exercise-sync-catalog-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode($entries, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalEntry(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'Testuebung',
            'equipment' => Equipment::Bodyweight->value,
            'muscleGroups' => ['rumpf'],
            'kneeLoad' => KneeLoad::None->value,
            'measure' => ExerciseMeasure::Reps->value,
            'description' => null,
            'isPrevention' => false,
            'kneeLoadReason' => 'Testbegruendung.',
        ];
    }
}
