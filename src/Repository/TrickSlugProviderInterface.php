<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Narrow seam so App\Validator\ExistingTrickSlugValidator can be built and
 * unit-tested without a kernel. App\Repository\TrickRepository is `final`
 * (T-0101), so PHPUnit cannot mock it and PHP cannot subclass it for a
 * hand-rolled fake either (T-0102 tests.md, "Abweichung von design.md §4").
 * TrickRepository already has a matching findSlugs() method and only needs
 * to declare `implements TrickSlugProviderInterface` - production wiring is
 * unaffected, autowiring still resolves to the same concrete repository.
 */
interface TrickSlugProviderInterface
{
    /**
     * @return list<string>
     */
    public function findSlugs(): array;
}
