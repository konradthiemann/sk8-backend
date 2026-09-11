<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Trick;
use App\Enum\TrickCategory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for later tickets (T-0102 and beyond) that need arbitrary
 * tricks. Not asserted against directly here; `slug` is a running sequence
 * so that `uniq_trick_slug` never breaks across repeated `create*()` calls.
 *
 * @extends PersistentObjectFactory<Trick>
 */
final class TrickFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Trick::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // A method-local static counter, not a class property: Foundry's
        // Factory base class is annotated @immutable, and PHPStan propagates
        // that readonly-ness to every property declared by subclasses too.
        /** @var int $sequence */
        static $sequence = 0;
        ++$sequence;

        return [
            'slug' => \sprintf('trick-%d', $sequence),
            'name' => self::faker()->words(2, true),
            'category' => self::faker()->randomElement(TrickCategory::cases()),
            'difficulty' => self::faker()->numberBetween(1, 10),
            'description' => self::faker()->optional()->sentence(),
            'isGoal' => false,
            'goalOrder' => null,
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-30 days', 'now')),
        ];
    }
}
