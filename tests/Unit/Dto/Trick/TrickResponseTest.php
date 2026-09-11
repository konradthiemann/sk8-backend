<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Trick;

use App\Dto\Trick\TrickResponse;
use App\Entity\Trick;
use App\Entity\TrickPrerequisite;
use App\Enum\TrickCategory;
use PHPUnit\Framework\TestCase;

/**
 * Pure mapping test: no kernel, no database. Builds the entity graph by hand
 * via the constructors and `Trick::addPrerequisite()` (see tests.md for the
 * assumed entity contract) and checks what `TrickResponse::fromEntity()`
 * produces from it.
 */
final class TrickResponseTest extends TestCase
{
    public function testItMapsAllNineFieldsFromAGoalTrick(): void
    {
        $manual = $this->buildTrick('manual', 'Manual', TrickCategory::Balance, 4, 'Auf den Hinterrädern rollen, Nose oben, Blick nach vorn.', false, null);
        $ollie = $this->buildTrick('ollie', 'Ollie', TrickCategory::Flat, 3, 'Ollie in der Fahrt.', true, 1);
        $ollieToManual = $this->buildTrick('ollie-to-manual', 'Ollie in den Manual', TrickCategory::Balance, 6, 'Ollie auf das Manual-Pad und direkt in den Manual abrollen.', true, 4);

        $ollieToManual->addPrerequisite(new TrickPrerequisite($ollieToManual, $ollie));
        $ollieToManual->addPrerequisite(new TrickPrerequisite($ollieToManual, $manual));

        $response = TrickResponse::fromEntity($ollieToManual);

        self::assertSame($ollieToManual->getId()->toRfc4122(), $response->id);
        self::assertSame('ollie-to-manual', $response->slug);
        self::assertSame('Ollie in den Manual', $response->name);
        self::assertSame('balance', $response->category);
        self::assertSame(6, $response->difficulty);
        self::assertSame('Ollie auf das Manual-Pad und direkt in den Manual abrollen.', $response->description);
        self::assertTrue($response->isGoal);
        self::assertSame(4, $response->goalOrder);
        self::assertSame(['manual', 'ollie'], $response->prerequisiteSlugs);
    }

    public function testItLeavesGoalOrderAndDescriptionNullForANonGoalTrick(): void
    {
        $trick = $this->buildTrick('board-stall', 'Board-Stall auf dem Curb', TrickCategory::Slide, 5, null, false, null);

        $response = TrickResponse::fromEntity($trick);

        self::assertFalse($response->isGoal);
        self::assertNull($response->goalOrder);
        self::assertNull($response->description);
    }

    public function testItReturnsAnEmptyListForATrickWithoutPrerequisites(): void
    {
        $rolling = $this->buildTrick('rolling', 'Sicher rollen', TrickCategory::Flat, 1, 'Pushen, Richtung halten, bremsen und in der Fahrt stehen.', false, null);

        $response = TrickResponse::fromEntity($rolling);

        self::assertSame([], $response->prerequisiteSlugs);
    }

    public function testItSortsPrerequisiteSlugsAlphabeticallyRegardlessOfInsertionOrder(): void
    {
        $trick = $this->buildTrick('pop-shove-it-to-manual', 'Pop Shove-it in den Manual', TrickCategory::Balance, 6, null, false, null);
        $manual = $this->buildTrick('manual', 'Manual', TrickCategory::Balance, 4, null, false, null);
        $popShoveIt = $this->buildTrick('pop-shove-it', 'Pop Shove-it', TrickCategory::Rotation, 4, null, false, null);

        // Added in reverse alphabetical order on purpose.
        $trick->addPrerequisite(new TrickPrerequisite($trick, $popShoveIt));
        $trick->addPrerequisite(new TrickPrerequisite($trick, $manual));

        $response = TrickResponse::fromEntity($trick);

        self::assertSame(['manual', 'pop-shove-it'], $response->prerequisiteSlugs);
    }

    private function buildTrick(
        string $slug,
        string $name,
        TrickCategory $category,
        int $difficulty,
        ?string $description,
        bool $isGoal,
        ?int $goalOrder,
    ): Trick {
        return new Trick(
            $slug,
            $name,
            $category,
            $difficulty,
            $description,
            $isGoal,
            $goalOrder,
            new \DateTimeImmutable('2026-09-07T10:00:00+00:00'),
        );
    }
}
