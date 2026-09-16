<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Exercise;
use App\Repository\ExerciseRepository;
use App\Service\Training\ExerciseCatalogFile;
use App\Service\Training\InvalidExerciseCatalogException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Idempotently syncs App\Entity\Exercise rows from a catalog file (in
 * production, config/data/exercises.json) into the database: creates rows
 * for slugs not yet known, updates changed fields on existing rows by slug,
 * and never deletes - a row whose slug disappeared from the file is left
 * untouched (T-0301 design.md §4/§5, acceptance criteria 7-12).
 *
 * Runs on every deploy, after the migration step, under the same condition
 * (frankenphp/docker-entrypoint.sh) - the catalog changes with every new
 * finding from R-03, so a repeated idempotent sync is cheaper and more
 * reliable than a manual step someone forgets after the third deploy.
 */
#[AsCommand(name: 'app:exercise:sync', description: 'Synchronizes the exercise catalog from the catalog file into the database')]
final class SyncExercisesCommand extends Command
{
    public function __construct(
        private readonly ExerciseCatalogFile $catalogFile,
        #[Autowire(param: 'app.exercise_catalog_path')]
        private readonly string $catalogFilePath,
        private readonly ExerciseRepository $exerciseRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would change, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $entries = $this->catalogFile->load($this->catalogFilePath);
        } catch (InvalidExerciseCatalogException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $existingBySlug = $this->exerciseRepository->findAllIndexedBySlug();
        $catalogSlugs = [];

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($entries as $entry) {
            $catalogSlugs[$entry->slug] = true;
            $existing = $existingBySlug[$entry->slug] ?? null;

            if (null === $existing) {
                $this->entityManager->persist(new Exercise(
                    slug: $entry->slug,
                    name: $entry->name,
                    equipment: $entry->equipment,
                    muscleGroups: $entry->muscleGroups,
                    kneeLoad: $entry->kneeLoad,
                    measure: $entry->measure,
                    description: $entry->description,
                    isPrevention: $entry->isPrevention,
                ));
                ++$created;

                continue;
            }

            if ($existing->updateFrom($entry)) {
                ++$updated;
            } else {
                ++$unchanged;
            }
        }

        $notInFile = 0;
        foreach ($existingBySlug as $slug => $exercise) {
            if (!isset($catalogSlugs[$slug])) {
                ++$notInFile;
            }
        }

        if ($dryRun) {
            $io->text('Trockenlauf, es wurde nichts geschrieben.');
        } else {
            $this->entityManager->flush();
        }

        $io->success(\sprintf(
            '%d angelegt, %d geaendert, %d unveraendert, %d nicht in der Datei.',
            $created,
            $updated,
            $unchanged,
            $notInFile,
        ));

        return Command::SUCCESS;
    }
}
