<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\BodyWeight;
use App\Enum\BodyWeightContext;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for T-0103 tests. Defaults to a standalone `context: morgens`
 * row with no attached skate session (ticket "Tests": "Standard context:
 * morgens ohne Einheit") - a body-weight row with no session is the
 * unmarked, default case here, the same way SkateSessionFactory defaults to
 * a session without trick rows.
 *
 * Assumes `BodyWeight::__construct()` takes its columns as named constructor
 * parameters (`measuredOn`, `measuredAt`, `weightKg`, `context`,
 * `skateSession`), mirroring `SessionTrick::__construct()` and
 * `SkateSession::__construct()` - see tests.md for the full assumed
 * contract.
 *
 * @extends PersistentObjectFactory<BodyWeight>
 */
final class BodyWeightFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return BodyWeight::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $measuredOn = self::faker()->dateTimeBetween('-30 days', '-1 days');
        $dateString = $measuredOn->format('Y-m-d');

        return [
            'measuredOn' => new \DateTimeImmutable($dateString),
            'measuredAt' => new \DateTimeImmutable($dateString.' 07:00:00', new \DateTimeZone('Europe/Berlin')),
            'weightKg' => number_format(self::faker()->randomFloat(2, 60, 95), 2, '.', ''),
            'context' => BodyWeightContext::Morning,
            'skateSession' => null,
        ];
    }
}
