<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\SessionTrick;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for T-0102 functional tests. Attaches one practiced-trick row
 * to a session. `skateSession` and `trick` default to a fresh
 * `SkateSessionFactory`/`TrickFactory` instance each, but every test that
 * cares about the relationship (which is most of them) overrides both
 * explicitly - see tests.md for the assumed `SessionTrick::__construct()` contract.
 *
 * `landed` always defaults to at most `attempts`, respecting the
 * `chk_session_trick_landed` database constraint.
 *
 * @extends PersistentObjectFactory<SessionTrick>
 */
final class SessionTrickFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return SessionTrick::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $attempts = self::faker()->numberBetween(5, 40);

        return [
            'skateSession' => SkateSessionFactory::new(),
            'trick' => TrickFactory::new(),
            'attempts' => $attempts,
            'landed' => self::faker()->numberBetween(0, $attempts),
            'notes' => self::faker()->optional()->sentence(),
        ];
    }
}
