<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Entity\Trick;
use App\Entity\TrickPrerequisite;
use App\Repository\TrickRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts the data-migration seed of the trick catalog directly against the
 * database (dama-rolled-back transaction). No HTTP layer involved, so this
 * uses KernelTestCase rather than ApiTestCase/WebTestCase.
 */
final class TrickCatalogSeedTest extends KernelTestCase
{
    /**
     * @var list<Trick>|null
     */
    private ?array $tricks = null;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testItSeedsExactlySixteenTricks(): void
    {
        self::assertCount(16, $this->allTricks());
    }

    public function testItSeedsExactlyEighteenPrerequisiteEdges(): void
    {
        $edgeCount = 0;
        foreach ($this->allTricks() as $trick) {
            $edgeCount += \count($trick->getPrerequisites());
        }

        self::assertSame(18, $edgeCount);
    }

    public function testItMarksExactlySevenTricksAsGoals(): void
    {
        $goals = array_filter(
            $this->allTricks(),
            static fn (Trick $trick): bool => $trick->isGoal(),
        );

        self::assertCount(7, $goals);
    }

    public function testItAssignsEveryGoalOrderFromOneToSevenExactlyOnce(): void
    {
        $goals = array_filter(
            $this->allTricks(),
            static fn (Trick $trick): bool => $trick->isGoal(),
        );

        $orders = array_map(static fn (Trick $trick): ?int => $trick->getGoalOrder(), array_values($goals));
        sort($orders);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], $orders);
    }

    public function testNoNonGoalTrickHasAGoalOrder(): void
    {
        $nonGoals = array_filter(
            $this->allTricks(),
            static fn (Trick $trick): bool => !$trick->isGoal(),
        );

        foreach ($nonGoals as $trick) {
            self::assertNull($trick->getGoalOrder(), \sprintf('non-goal trick "%s" has a goalOrder', $trick->getSlug()));
        }
    }

    public function testNoPrerequisiteEdgePointsToTheTrickItself(): void
    {
        foreach ($this->allTricks() as $trick) {
            foreach ($trick->getPrerequisites() as $prerequisite) {
                self::assertFalse(
                    $trick->getId()->equals($prerequisite->getRequiresTrick()->getId()),
                    \sprintf('trick "%s" requires itself', $trick->getSlug()),
                );
            }
        }
    }

    public function testEveryPrerequisiteEdgePointsToTwoExistingTricks(): void
    {
        $knownSlugs = array_map(static fn (Trick $trick): string => $trick->getSlug(), $this->allTricks());

        foreach ($this->allTricks() as $trick) {
            self::assertContains($trick->getSlug(), $knownSlugs);

            foreach ($trick->getPrerequisites() as $prerequisite) {
                self::assertContains($prerequisite->getRequiresTrick()->getSlug(), $knownSlugs);
            }
        }
    }

    public function testThePrerequisiteGraphContainsNoCycle(): void
    {
        $bySlug = $this->adjacencyBySlug();

        $visited = [];
        $inProgress = [];

        $visit = static function (string $slug) use (&$visit, &$visited, &$inProgress, $bySlug): void {
            if (isset($visited[$slug])) {
                return;
            }

            self::assertArrayNotHasKey($slug, $inProgress, \sprintf('cycle detected involving "%s"', $slug));

            $inProgress[$slug] = true;
            foreach ($bySlug[$slug] ?? [] as $requiredSlug) {
                $visit($requiredSlug);
            }
            unset($inProgress[$slug]);

            $visited[$slug] = true;
        };

        foreach (array_keys($bySlug) as $slug) {
            $visit($slug);
        }

        self::assertCount(\count($bySlug), $visited);
    }

    public function testEveryGoalCanReachRollingThroughItsPrerequisites(): void
    {
        $bySlug = $this->adjacencyBySlug();

        foreach ($this->allTricks() as $trick) {
            if (!$trick->isGoal()) {
                continue;
            }

            $seen = [];
            self::assertTrue(
                $this->reachesRolling($trick->getSlug(), $bySlug, $seen),
                \sprintf('goal "%s" cannot reach "rolling" through its prerequisites', $trick->getSlug()),
            );
        }
    }

    /**
     * @param array<string, list<string>> $bySlug
     * @param array<string, true>         $seen
     */
    private function reachesRolling(string $slug, array $bySlug, array &$seen): bool
    {
        if ('rolling' === $slug) {
            return true;
        }

        if (isset($seen[$slug])) {
            return false;
        }
        $seen[$slug] = true;

        foreach ($bySlug[$slug] ?? [] as $requiredSlug) {
            if ($this->reachesRolling($requiredSlug, $bySlug, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>> slug => list of required slugs
     */
    private function adjacencyBySlug(): array
    {
        $bySlug = [];
        foreach ($this->allTricks() as $trick) {
            $bySlug[$trick->getSlug()] = array_values(array_map(
                static fn (TrickPrerequisite $prerequisite): string => $prerequisite->getRequiresTrick()->getSlug(),
                $trick->getPrerequisites()->toArray(),
            ));
        }

        return $bySlug;
    }

    /**
     * @return list<Trick>
     */
    private function allTricks(): array
    {
        if (null !== $this->tricks) {
            return $this->tricks;
        }

        $repository = static::getContainer()->get(TrickRepository::class);
        self::assertInstanceOf(TrickRepository::class, $repository);

        return $this->tricks = $repository->findAllOrdered();
    }
}
