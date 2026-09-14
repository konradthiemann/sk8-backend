<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Dto\Trick\PauseHintView;
use App\Dto\Trick\TrickDosage;
use App\Dto\Trick\TrickRecommendationResponse;
use App\Dto\Trick\TrickSuggestion;
use App\Entity\Trick;
use App\Enum\TrickStatus;
use App\Repository\SkateSessionLoadRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestrates GET /api/trick-recommendation (T-0202 design.md §4/§6): reuses
 * App\Service\Trick\TrickTreeService::currentSnapshot() for the catalog and
 * derived statuses, ranks the practicing/ready candidates, attaches a
 * RecommendationReason to each pick and a PauseHint from the most recent
 * session data.
 *
 * selectSuggestions() is `public static` on purpose (design.md §4: "damit
 * TrickRecommender::selectSuggestions() ohne Kernel testbar ist") - it is the
 * pure ranking rule the ticket asked to keep testable without a database,
 * while recommend() itself wires the real Doctrine dependencies
 * (TrickTreeService, SkateSessionLoadRepository) that a kernel-free unit test
 * must not need to construct.
 */
final readonly class TrickRecommender
{
    public function __construct(
        private TrickTreeService $trickTreeService,
        private SkateSessionLoadRepository $skateSessionLoadRepository,
        #[Autowire(param: 'app.timezone')]
        private string $timezone,
    ) {
    }

    public function recommend(): TrickRecommendationResponse
    {
        $snapshot = $this->trickTreeService->currentSnapshot();
        $candidates = self::candidatesFromSnapshot($snapshot);

        [$primaryCandidate, $secondaryCandidates] = self::selectSuggestions($candidates);

        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
        $pauseHint = PauseHint::resolve(
            $this->skateSessionLoadRepository->lastKneePain(),
            $this->skateSessionLoadRepository->consecutiveSessionDays($now),
        );

        return new TrickRecommendationResponse(
            null !== $primaryCandidate ? self::toSuggestion($primaryCandidate) : null,
            array_map(self::toSuggestion(...), $secondaryCandidates),
            TrickRecommendationPolicy::FOCUS_LIMIT,
            null !== $pauseHint ? new PauseHintView($pauseHint->code, $pauseHint->message) : null,
            $now->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * Pure ranking rule (design.md §6 sequence diagram note): filters to
     * Practicing/Ready candidates *inside* this method (criterion 9 callers
     * may pass candidates of any status), sorts by (status, goalOrder,
     * difficulty, slug) - Practicing before Ready, then ascending goalOrder
     * with null last, then ascending difficulty, then the slug as a final,
     * deterministic tie-break (criterion 7) - and keeps the top FOCUS_LIMIT.
     *
     * @param list<TrickCandidate> $candidates
     *
     * @return array{0: ?TrickCandidate, 1: list<TrickCandidate>}
     */
    public static function selectSuggestions(array $candidates): array
    {
        $eligible = array_values(array_filter(
            $candidates,
            static fn (TrickCandidate $candidate): bool => TrickStatus::Practicing === $candidate->status || TrickStatus::Ready === $candidate->status,
        ));

        usort($eligible, self::compare(...));

        $selected = \array_slice($eligible, 0, TrickRecommendationPolicy::FOCUS_LIMIT);

        if ([] === $selected) {
            return [null, []];
        }

        return [$selected[0], \array_slice($selected, 1)];
    }

    private static function compare(TrickCandidate $a, TrickCandidate $b): int
    {
        $byStatus = self::statusRank($a->status) <=> self::statusRank($b->status);
        if (0 !== $byStatus) {
            return $byStatus;
        }

        $byGoalOrder = ($a->goalOrder ?? \PHP_INT_MAX) <=> ($b->goalOrder ?? \PHP_INT_MAX);
        if (0 !== $byGoalOrder) {
            return $byGoalOrder;
        }

        $byDifficulty = $a->difficulty <=> $b->difficulty;

        return 0 !== $byDifficulty ? $byDifficulty : strcmp($a->slug, $b->slug);
    }

    /**
     * Practicing outranks Ready (criterion 6): a trick already underway is a
     * more useful "next focus" than one merely unlocked.
     */
    private static function statusRank(TrickStatus $status): int
    {
        return TrickStatus::Practicing === $status ? 0 : 1;
    }

    /**
     * @return list<TrickCandidate>
     */
    private static function candidatesFromSnapshot(TrickCatalogSnapshot $snapshot): array
    {
        return array_map(
            static function (Trick $trick) use ($snapshot): TrickCandidate {
                $trickId = $trick->getId()->toRfc4122();
                $stats = $snapshot->stats[$trickId] ?? null;

                return new TrickCandidate(
                    slug: $trick->getSlug(),
                    name: $trick->getName(),
                    status: $snapshot->statuses[$trickId] ?? TrickStatus::Locked,
                    goalOrder: $trick->getGoalOrder(),
                    difficulty: $trick->getDifficulty(),
                    recentSuccessRate: $stats?->recentSuccessRate(),
                );
            },
            $snapshot->tricks,
        );
    }

    private static function toSuggestion(TrickCandidate $candidate): TrickSuggestion
    {
        $reason = RecommendationReason::forCandidate($candidate->status, $candidate->recentSuccessRate);

        return new TrickSuggestion(
            $candidate->slug,
            $candidate->name,
            $candidate->status->value,
            $reason->code,
            $reason->message,
            TrickDosage::current(),
        );
    }
}
