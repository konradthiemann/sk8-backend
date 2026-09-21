<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Habit\HabitCatalogSynchronizer;
use App\Service\Habit\InvalidHabitCatalogException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mirrors App\Service\Habit\HabitCatalog into the `habit` table (T-0401).
 * Run by hand, not on deploy: unlike the exercise catalog, a faulty habit
 * catalog must not be able to block a container start. After a deploy that
 * changes the catalog, run `--dry-run` first, then the real sync.
 */
#[AsCommand(name: 'app:habits:sync', description: 'Synchronizes the habit catalog from the typed catalog into the database')]
final class HabitsSyncCommand extends Command
{
    public function __construct(private readonly HabitCatalogSynchronizer $synchronizer)
    {
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
            $result = $this->synchronizer->sync($dryRun);
        } catch (InvalidHabitCatalogException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->text('Trockenlauf, es wurde nichts geschrieben.');
        }

        if ([] !== $result->changes) {
            $io->table(
                ['Slug', 'Aktion', 'Felder'],
                array_map(
                    static fn ($change): array => [$change->slug, $change->kind->value, implode(', ', $change->fields)],
                    $result->changes,
                ),
            );
        }

        $io->success(\sprintf(
            'created: %d, updated: %d, deactivated: %d, unchanged: %d',
            $result->created,
            $result->updated,
            $result->deactivated,
            $result->unchanged,
        ));

        return Command::SUCCESS;
    }
}
