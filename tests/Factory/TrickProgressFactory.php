<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\TrickProgress;
use App\Enum\TrickStatus;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for T-0201 tests. New, per this ticket's "Tests" section -
 * unlike TrickFactory/SessionTrickFactory/SkateSessionFactory (EPIC-01,
 * untouched), there was no prior factory for this entity to reuse.
 *
 * Assumes `App\Entity\TrickProgress::__construct()` takes exactly
 * `(Trick $trick, TrickStatus $status, \DateTimeImmutable $now)` (design.md
 * §4, "Konstruktor... für den Neuanlage-Fall") - deliberately narrower than
 * the row's full field set (`attemptsTotal`, `landedTotal`, `firstLandedOn`),
 * which the constructor does not accept directly. Those three fields are
 * applied afterwards through `applyIfChanged()`, the same method
 * `TrickProgressRefresher` itself is expected to call (design.md §4, §6
 * pseudocode) - so a factory-built row goes through the identical code path
 * a real refresh would, rather than reaching into private state. This mirrors
 * how `TrickProgress` normalizes "brand new" vs. "already existing" rows: a
 * fresh instance always starts at the zero/null defaults and only
 * `applyIfChanged()` ever writes the real numbers.
 *
 * `attemptsTotal`, `landedTotal` and `firstLandedOn` are not constructor
 * parameters, so the default `Instantiator::withConstructor()` would try (and
 * fail) to hydrate them as properties with no public setter - `allowExtra()`
 * tells Foundry's hydrator to leave those three keys alone; `afterInstantiate()`
 * then applies them via `applyIfChanged()` instead.
 *
 * @extends PersistentObjectFactory<TrickProgress>
 */
final class TrickProgressFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return TrickProgress::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'trick' => TrickFactory::new(),
            'status' => TrickStatus::Ready,
            'now' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-30 days', 'now')),
            // Not constructor parameters - see class doc comment. Applied via
            // applyIfChanged() in initialize()'s afterInstantiate() hook.
            'attemptsTotal' => 0,
            'landedTotal' => 0,
            'firstLandedOn' => null,
        ];
    }

    protected function initialize(): static
    {
        return parent::initialize()
            ->instantiateWith(
                Instantiator::withConstructor()->allowExtra('attemptsTotal', 'landedTotal', 'firstLandedOn'),
            )
            ->afterInstantiate(static function (TrickProgress $trickProgress, array $parameters): void {
                \assert($parameters['status'] instanceof TrickStatus);
                \assert(\is_int($parameters['attemptsTotal']));
                \assert(\is_int($parameters['landedTotal']));
                \assert(null === $parameters['firstLandedOn'] || $parameters['firstLandedOn'] instanceof \DateTimeImmutable);
                \assert($parameters['now'] instanceof \DateTimeImmutable);

                $trickProgress->applyIfChanged(
                    $parameters['status'],
                    $parameters['attemptsTotal'],
                    $parameters['landedTotal'],
                    $parameters['firstLandedOn'],
                    $parameters['now'],
                );
            });
    }
}
