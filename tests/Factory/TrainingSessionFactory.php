<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\TrainingSession;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for T-0302 functional tests. Produces a session *without*
 * training sets by default, on purpose - mirrors SkateSessionFactory's own
 * convention (T-0102 tests.md): every test that needs sets has to attach
 * them explicitly via TrainingSetFactory, so a set-less TrainingSession
 * entity (a valid ORM/DB state - the "at least one set" rule lives in
 * TrainingSessionRequest's Assert\Count, not a database constraint) stays
 * the unmarked, default case.
 *
 * Assumes TrainingSession::__construct() takes its columns as named
 * constructor parameters in the order design.md §2 gives them (sessionDate,
 * durationMinutes, perceivedExertion, kneePain, notes, createdAt), mirroring
 * SkateSession::__construct() - see tests.md for the full assumed contract.
 *
 * @extends PersistentObjectFactory<TrainingSession>
 */
final class TrainingSessionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return TrainingSession::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $sessionDate = self::faker()->dateTimeBetween('-30 days', '-1 days');

        return [
            'sessionDate' => new \DateTimeImmutable($sessionDate->format('Y-m-d')),
            'durationMinutes' => self::faker()->numberBetween(20, 90),
            'perceivedExertion' => null,
            'kneePain' => null,
            'notes' => null,
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-30 days', 'now')),
        ];
    }
}
