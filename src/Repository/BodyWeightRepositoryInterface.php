<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BodyWeight;
use App\Enum\BodyWeightContext;
use Symfony\Component\Uid\Uuid;

/**
 * Narrow seam so App\Service\Body\BodyWeightSynchronizer can be built and
 * unit-tested without a kernel. App\Repository\BodyWeightRepository is
 * `final` (same pattern as every other repository in this codebase), so
 * PHPUnit cannot mock it and PHP cannot subclass it for a hand-rolled fake
 * either - the same problem T-0102 solved for `TrickRepository` via
 * `TrickSlugProviderInterface` (see that file's doc comment; T-0103
 * tests.md, "Abweichung von design.md §4", documents this pragmatic
 * decision). `BodyWeightRepository` already has both matching methods per
 * design.md's table and only needs to declare `implements
 * BodyWeightRepositoryInterface` - production wiring is unaffected,
 * autowiring still resolves to the same concrete repository.
 */
interface BodyWeightRepositoryInterface
{
    public function findOneBySessionAndContext(Uuid $sessionId, BodyWeightContext $context): ?BodyWeight;

    /**
     * @return list<BodyWeight>
     */
    public function findBySession(Uuid $sessionId): array;
}
