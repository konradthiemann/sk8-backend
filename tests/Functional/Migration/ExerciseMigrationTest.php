<?php

declare(strict_types=1);

namespace App\Tests\Functional\Migration;

use App\Repository\ExerciseRepository;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies acceptance criterion 2 ("down() beider Migrationen laeuft ohne
 * Fehler, DB im Ausgangszustand") for the two new Exercise migrations.
 *
 * No prior art in this repo for reversibility (`find tests -iname
 * "*Migration*"` finds nothing; `composer.json`'s `test` script only ever
 * migrates forward) - design.md §4 fixes the convention used here: drive
 * Doctrine's own `DependencyFactory`/`Migrator` from the container rather
 * than a `bin/console` subprocess or a direct `up($schema)`/`down($schema)`
 * call on the migration class (those only collect SQL strings onto the
 * migration object, they never execute anything against the database).
 *
 * Deliberately runs outside dama's per-test transaction rollback (design.md
 * §4: "dama kapselt den Doctrine-ORM-Entity-Manager-Connection, der
 * Migrations-Runner nutzt eine eigene DBAL-Connection") - this test restores
 * the schema itself by migrating back up at the end, which is exactly the
 * behaviour criterion 2 is asserting. It must therefore leave the database
 * exactly as it found it even if an assertion fails midway - the up() at the
 * end is not optional cleanup, it is the thing under test.
 *
 * Tester's resolution of an open question the ticket leaves out (no fixed
 * migration version/timestamp exists yet): the two Exercise migrations are
 * found by `getDescription()` rather than a hardcoded class name. Every
 * migration already in this repo gives `getDescription()` a sentence naming
 * the table it touches (Version20260909100000: "Create trick and
 * trick_prerequisite tables ..."; Version20260909100001: "Seed the 16
 * catalog tricks ...") - the two new Exercise migrations are expected to
 * follow the same convention and mention "exercise" (case-insensitive) in
 * their description. tests.md documents this assumption.
 */
final class ExerciseMigrationTest extends KernelTestCase
{
    public function testDownThenUpOnBothExerciseMigrationsLeavesTheCatalogAsItWas(): void
    {
        self::bootKernel();

        $dependencyFactory = static::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $dependencyFactory);

        $exerciseRepository = static::getContainer()->get(ExerciseRepository::class);
        self::assertInstanceOf(ExerciseRepository::class, $exerciseRepository);

        $connection = $dependencyFactory->getConnection();

        $countBefore = \count($exerciseRepository->findAllOrdered());
        self::assertGreaterThan(0, $countBefore, 'the data migration must already have seeded the catalog before this test runs');
        self::assertTrue($connection->createSchemaManager()->tablesExist(['exercise']));

        $allMigrations = $dependencyFactory->getMigrationPlanCalculator()->getMigrations()->getItems();
        usort(
            $allMigrations,
            static fn (AvailableMigration $a, AvailableMigration $b): int => (string) $a->getVersion() <=> (string) $b->getVersion(),
        );

        $exerciseIndexes = [];
        foreach ($allMigrations as $index => $migration) {
            if (str_contains(strtolower($migration->getMigration()->getDescription()), 'exercise')) {
                $exerciseIndexes[] = $index;
            }
        }
        self::assertCount(2, $exerciseIndexes, 'expected exactly one schema and one data migration mentioning "exercise" in their description');
        sort($exerciseIndexes);
        [$schemaIndex, $dataIndex] = $exerciseIndexes;
        self::assertSame($schemaIndex + 1, $dataIndex, 'the schema migration must immediately precede the data migration (design.md §4: two separate, consecutive migrations)');
        self::assertGreaterThan(0, $schemaIndex, 'a migration must already exist before the exercise schema migration to migrate down to');

        $versionBeforeExercise = $allMigrations[$schemaIndex - 1]->getVersion();
        $dataMigrationVersion = $allMigrations[$dataIndex]->getVersion();

        // Down: reverses the data migration, then the schema migration.
        $downPlan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($versionBeforeExercise);
        $dependencyFactory->getMigrator()->migrate($downPlan, new MigratorConfiguration());

        self::assertFalse(
            $connection->createSchemaManager()->tablesExist(['exercise']),
            'down() of the schema migration must drop the exercise table',
        );

        // Up: recreates the schema, then reseeds the catalog - this is the
        // state the test must leave behind for every other test in the suite.
        $upPlan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($dataMigrationVersion);
        $dependencyFactory->getMigrator()->migrate($upPlan, new MigratorConfiguration());

        self::assertTrue($connection->createSchemaManager()->tablesExist(['exercise']));
        self::assertCount($countBefore, $exerciseRepository->findAllOrdered(), 'up() must reseed exactly the catalog that was there before');
    }
}
