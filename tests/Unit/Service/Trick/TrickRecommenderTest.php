<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Trick;

use App\Enum\TrickStatus;
use App\Service\Trick\TrickCandidate;
use App\Service\Trick\TrickRecommendationPolicy;
use App\Service\Trick\TrickRecommender;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP object test, no kernel, no database (design.md §4: "TrickCandidate
 * ... damit TrickRecommender::selectSuggestions() ohne Kernel testbar ist").
 *
 * Interpretation note (design.md does not spell this out explicitly):
 * `selectSuggestions()` is assumed `public static`, not an instance method -
 * the surrounding class docs describe it only as "die reine Rangfolge-Funktion"
 * and `TrickRecommender`'s constructor otherwise wires real Doctrine
 * dependencies (TrickTreeService, SkateSessionLoadRepository) that a
 * kernel-free unit test must not need to construct just to reach a pure
 * ranking function.
 *
 * Per design.md §6 sequence diagram note on the `selectSuggestions(candidates)`
 * step ("Filter uebe/bereit -> sortieren (Status, goalOrder, difficulty,
 * slug) -> FOCUS_LIMIT"), the filtering to Practicing/Ready candidates is
 * assumed to happen *inside* selectSuggestions() itself, not before the call -
 * so candidates of any status may be passed in here, including Locked/Mastered
 * ones (criterion 9).
 */
final class TrickRecommenderTest extends TestCase
{
    public function testFocusLimitConstantIsTheConfirmedRZeroTwoValue(): void
    {
        // Fixture guard, same pattern as TrickProgressPolicyTest: reflection
        // instead of a direct class-const reference, so PHPStan cannot fold
        // the assertSame() below into an always-true tautology.
        self::assertSame(2, self::policyConstant('FOCUS_LIMIT'));
    }

    public function testItPutsAPracticingCandidateOnPrimaryOverReadyCandidates(): void
    {
        // Criterion 6.
        $practicing = self::candidate(slug: 'ollie-stand', status: TrickStatus::Practicing, goalOrder: null, difficulty: 2);
        $readyOne = self::candidate(slug: 'rolling', status: TrickStatus::Ready, goalOrder: null, difficulty: 1);
        $readyTwo = self::candidate(slug: 'tic-tac', status: TrickStatus::Ready, goalOrder: null, difficulty: 1);

        [$primary] = TrickRecommender::selectSuggestions([$readyOne, $readyTwo, $practicing]);

        self::assertNotNull($primary);
        self::assertSame('ollie-stand', $primary->slug);
        self::assertSame(TrickStatus::Practicing, $primary->status);
    }

    public function testItOrdersByGoalOrderAscendingWithTricksWithoutGoalOrderLast(): void
    {
        $withGoalOrderThree = self::candidate(slug: 'manual', status: TrickStatus::Ready, goalOrder: 3, difficulty: 4);
        $withGoalOrderOne = self::candidate(slug: 'ollie', status: TrickStatus::Ready, goalOrder: 1, difficulty: 3);
        $withoutGoalOrder = self::candidate(slug: 'rolling', status: TrickStatus::Ready, goalOrder: null, difficulty: 1);

        [$primary, $secondary] = TrickRecommender::selectSuggestions([$withoutGoalOrder, $withGoalOrderThree, $withGoalOrderOne]);

        self::assertNotNull($primary);
        self::assertSame('ollie', $primary->slug, 'lowest goalOrder must win primary');
        self::assertCount(1, $secondary, 'FOCUS_LIMIT=2 leaves room for exactly one secondary slot');
        self::assertSame('manual', $secondary[0]->slug, 'trick without goalOrder must rank behind both goalOrder holders');
    }

    public function testItFallsBackToDifficultyThenSlugWhenGoalOrderTies(): void
    {
        $harder = self::candidate(slug: 'kickflip', status: TrickStatus::Ready, goalOrder: null, difficulty: 8);
        $easier = self::candidate(slug: 'ollie-stand', status: TrickStatus::Ready, goalOrder: null, difficulty: 2);

        [$primary] = TrickRecommender::selectSuggestions([$harder, $easier]);

        self::assertNotNull($primary);
        self::assertSame('ollie-stand', $primary->slug, 'lower difficulty must win when goalOrder ties (both null)');
    }

    public function testItProducesTheSameOrderOnRepeatedCallsWhenCandidatesTieOnStatusGoalOrderAndDifficulty(): void
    {
        // Criterion 7: identical status, goalOrder and difficulty - only the
        // slug can break the tie, and it must do so the same way every time.
        $first = self::candidate(slug: 'shove-it-stand', status: TrickStatus::Ready, goalOrder: null, difficulty: 2);
        $second = self::candidate(slug: 'ollie-stand', status: TrickStatus::Ready, goalOrder: null, difficulty: 2);

        [$primaryA, $secondaryA] = TrickRecommender::selectSuggestions([$first, $second]);
        [$primaryB, $secondaryB] = TrickRecommender::selectSuggestions([$first, $second]);

        self::assertNotNull($primaryA);
        self::assertNotNull($primaryB);
        self::assertSame($primaryA->slug, $primaryB->slug, 'repeated calls on the same input must agree on primary');
        self::assertSame('ollie-stand', $primaryA->slug, 'alphabetically first slug must win the tie');
        self::assertSame(
            array_map(static fn (TrickCandidate $c): string => $c->slug, $secondaryA),
            array_map(static fn (TrickCandidate $c): string => $c->slug, $secondaryB),
        );
    }

    public function testItLimitsTheTotalNumberOfSuggestionsToFocusLimitWhenMoreCandidatesQualify(): void
    {
        // Criterion 8: five eligible candidates, FOCUS_LIMIT = 2 total.
        $candidates = [
            self::candidate(slug: 'rolling', status: TrickStatus::Ready, goalOrder: null, difficulty: 1),
            self::candidate(slug: 'tic-tac', status: TrickStatus::Ready, goalOrder: null, difficulty: 1),
            self::candidate(slug: 'drop-in', status: TrickStatus::Ready, goalOrder: null, difficulty: 2),
            self::candidate(slug: 'ollie-stand', status: TrickStatus::Ready, goalOrder: null, difficulty: 2),
            self::candidate(slug: 'shove-it-stand', status: TrickStatus::Ready, goalOrder: null, difficulty: 2),
        ];

        [$primary, $secondary] = TrickRecommender::selectSuggestions($candidates);

        self::assertNotNull($primary);
        self::assertCount(1, $secondary);
        self::assertSame(TrickRecommendationPolicy::FOCUS_LIMIT, 1 + \count($secondary));
    }

    public function testItReturnsNullPrimaryAndEmptySecondaryForAnEmptyCandidateList(): void
    {
        // Criterion 9, empty-input half.
        [$primary, $secondary] = TrickRecommender::selectSuggestions([]);

        self::assertNull($primary);
        self::assertSame([], $secondary);
    }

    public function testItReturnsNullPrimaryAndEmptySecondaryWhenOnlyLockedOrMasteredCandidatesArePresent(): void
    {
        // Criterion 9: exclusively gesperrt/sitzt tricks - none of them is
        // Practicing or Ready, so none may ever surface as a suggestion.
        $locked = self::candidate(slug: 'boardslide', status: TrickStatus::Locked, goalOrder: 6, difficulty: 7);
        $mastered = self::candidate(slug: 'ollie', status: TrickStatus::Mastered, goalOrder: 1, difficulty: 3);

        [$primary, $secondary] = TrickRecommender::selectSuggestions([$locked, $mastered]);

        self::assertNull($primary);
        self::assertSame([], $secondary);
    }

    private static function candidate(
        string $slug,
        TrickStatus $status,
        ?int $goalOrder,
        int $difficulty,
        ?float $recentSuccessRate = null,
    ): TrickCandidate {
        return new TrickCandidate(
            slug: $slug,
            name: $slug,
            status: $status,
            goalOrder: $goalOrder,
            difficulty: $difficulty,
            recentSuccessRate: $recentSuccessRate,
        );
    }

    private static function policyConstant(string $name): mixed
    {
        return (new \ReflectionClassConstant(TrickRecommendationPolicy::class, $name))->getValue();
    }
}
