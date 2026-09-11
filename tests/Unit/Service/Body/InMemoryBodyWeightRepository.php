<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Body;

use App\Entity\BodyWeight;
use App\Enum\BodyWeightContext;
use App\Repository\BodyWeightRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * In-memory test double backing BodyWeightSynchronizerTest (no kernel, no
 * database).
 *
 * `App\Repository\BodyWeightRepositoryInterface` does not exist yet in
 * `App\Repository` - it is this test's own seam, not something design.md
 * specifies. Design.md §4 types `BodyWeightSynchronizer`'s constructor
 * against the concrete `App\Repository\BodyWeightRepository` directly. Every
 * existing repository in this codebase (`TrickRepository`,
 * `SkateSessionRepository`) is declared `final`, and `BodyWeightRepository`
 * is expected to follow the same pattern (design.md §4: "wie
 * SkateSessionRepository.php") - which would make it unmockable by PHPUnit
 * and unfakeable by subclassing, exactly the problem T-0102 solved for
 * `TrickRepository` via `TrickSlugProviderInterface`
 * (src/Repository/TrickSlugProviderInterface.php). This test follows the
 * same fix: `BodyWeightSynchronizer` is expected to depend on this narrow
 * interface (both methods `BodyWeightRepository` already offers per
 * design.md's table) instead of the concrete class - see tests.md,
 * "Abweichung von design.md §4" for the full rationale. Production wiring is
 * unaffected: `BodyWeightRepository` only needs to additionally declare
 * `implements BodyWeightRepositoryInterface`, still the only implementation,
 * still autowired the same way.
 */
final class InMemoryBodyWeightRepository implements BodyWeightRepositoryInterface
{
    /**
     * @var list<BodyWeight>
     */
    public array $rows = [];

    public function findOneBySessionAndContext(Uuid $sessionId, BodyWeightContext $context): ?BodyWeight
    {
        foreach ($this->rows as $row) {
            $session = $row->getSkateSession();
            if (null !== $session && $session->getId()->equals($sessionId) && $context === $row->getContext()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<BodyWeight>
     */
    public function findBySession(Uuid $sessionId): array
    {
        return array_values(array_filter(
            $this->rows,
            static function (BodyWeight $row) use ($sessionId): bool {
                $session = $row->getSkateSession();

                return null !== $session && $session->getId()->equals($sessionId);
            },
        ));
    }
}
