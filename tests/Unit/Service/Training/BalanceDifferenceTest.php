<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Training;

use App\Enum\BodySide;
use App\Service\Training\BalanceDifference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure function, no kernel, no database: `BalanceDifference::between()` is
 * the single place where the single-leg balance asymmetry is derived. The
 * table below is the binding value table of design.md 4.1.
 */
final class BalanceDifferenceTest extends TestCase
{
    #[DataProvider('differenceCases')]
    public function testItDerivesTheDifferenceAndTheWeakerSide(
        ?int $left,
        ?int $right,
        ?int $expectedSeconds,
        ?BodySide $expectedWeakerSide,
    ): void {
        $difference = BalanceDifference::between($left, $right);

        self::assertSame($expectedSeconds, $difference->seconds);
        self::assertSame($expectedWeakerSide, $difference->weakerSide);
    }

    /**
     * @return iterable<string, array{?int, ?int, ?int, ?BodySide}>
     */
    public static function differenceCases(): iterable
    {
        // Criterion 7.
        yield 'left is weaker' => [28, 51, 23, BodySide::Links];
        yield 'right is weaker' => [51, 28, 23, BodySide::Rechts];
        // Criterion 9: a tie has a difference of zero but no weaker side.
        yield 'equal sides' => [30, 30, 0, null];
        // Criterion 8: one missing side means nothing can be compared.
        yield 'left missing' => [null, 51, null, null];
        yield 'right missing' => [28, null, null, null];
        yield 'both missing' => [null, null, null, null];
        // 0 is a valid measurement (could not stand at all), not "missing".
        yield 'left is zero' => [0, 40, 40, BodySide::Links];
        yield 'right is zero' => [40, 0, 40, BodySide::Rechts];
        yield 'both are zero' => [0, 0, 0, null];
    }
}
