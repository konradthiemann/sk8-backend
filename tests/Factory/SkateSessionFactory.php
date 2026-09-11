<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\SkateSession;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for T-0102 functional tests. Produces a session *without*
 * trick rows by default, on purpose (tests.md): every test that needs tricks
 * has to attach them explicitly via `SessionTrickFactory`, so "a session with
 * no tricks is a valid session" (criterion 4) stays the unmarked, default case
 * instead of something every other test has to opt out of.
 *
 * Assumes `SkateSession::__construct()` takes its columns as named
 * constructor parameters, mirroring `Trick::__construct()`
 * (src/Entity/Trick.php) - see tests.md for the full assumed contract.
 *
 * @extends PersistentObjectFactory<SkateSession>
 */
final class SkateSessionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return SkateSession::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $sessionDate = self::faker()->dateTimeBetween('-30 days', '-1 days');

        return [
            'sessionDate' => new \DateTimeImmutable($sessionDate->format('Y-m-d')),
            'startedAt' => null,
            'durationMinutes' => self::faker()->numberBetween(20, 120),
            'location' => self::faker()->randomElement(['Skatepark Braunschweig', 'Halle Wolfenbüttel', 'Spot Innenstadt']),
            'weightBeforeKg' => null,
            'weightAfterKg' => null,
            'perceivedExertion' => null,
            'kneePain' => null,
            'notes' => null,
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-30 days', 'now')),
        ];
    }
}
