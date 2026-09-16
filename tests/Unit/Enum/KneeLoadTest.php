<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\KneeLoad;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure enum test, no kernel, no database: `rank()` is the numeric ordering
 * that T-0304's alternative-exercise search relies on to compare two knee
 * loads (design.md §2, "App\Enum\KneeLoad ... dazu rank(): int (0 bis 3)
 * fuer Vergleiche").
 */
final class KneeLoadTest extends TestCase
{
    #[DataProvider('rankCases')]
    public function testItRanksEachCaseByAscendingKneeLoad(KneeLoad $kneeLoad, int $expectedRank): void
    {
        self::assertSame($expectedRank, $kneeLoad->rank());
    }

    /**
     * @return iterable<string, array{KneeLoad, int}>
     */
    public static function rankCases(): iterable
    {
        yield 'none is the lowest rank' => [KneeLoad::None, 0];
        yield 'low ranks above none' => [KneeLoad::Low, 1];
        yield 'medium ranks above low' => [KneeLoad::Medium, 2];
        yield 'high is the highest rank' => [KneeLoad::High, 3];
    }

    public function testItOrdersAllFourCasesStrictlyAscendingByRank(): void
    {
        $ranks = array_map(static fn (KneeLoad $kneeLoad): int => $kneeLoad->rank(), KneeLoad::cases());

        $sorted = $ranks;
        sort($sorted);

        self::assertSame($sorted, $ranks, 'KneeLoad::cases() is expected to already be keine < niedrig < mittel < hoch');
        self::assertSame([0, 1, 2, 3], $ranks);
    }

    public function testItBacksEachCaseWithItsGermanDatabaseValue(): void
    {
        // design.md §2: case names stay English identifiers (ADR-008), but
        // the backing values are the German strings chk_exercise_knee_load
        // checks against - same pattern as App\Enum\TrickStatus.
        self::assertSame('keine', KneeLoad::None->value);
        self::assertSame('niedrig', KneeLoad::Low->value);
        self::assertSame('mittel', KneeLoad::Medium->value);
        self::assertSame('hoch', KneeLoad::High->value);
    }
}
