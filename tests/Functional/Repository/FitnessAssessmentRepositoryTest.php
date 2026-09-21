<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Repository\FitnessAssessmentRepository;
use App\Tests\Factory\FitnessAssessmentFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test): the
 * repository is deliberately thin (`findBy`/`count` with a
 * `\DateTimeImmutable` on a `date_immutable` column), and only a real
 * database can prove that the parameter binding and the ordering behave as
 * design.md 4.1 assumes.
 */
final class FitnessAssessmentRepositoryTest extends KernelTestCase
{
    use Factories;

    public function testFindPageReturnsTheNewestAssessmentsFirst(): void
    {
        $repository = $this->repository();
        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-01')]);
        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-03')]);
        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-02')]);

        $page = $repository->findPage(30, 0);

        self::assertSame(
            ['2026-09-03', '2026-09-02', '2026-09-01'],
            array_map(static fn ($assessment): string => $assessment->getAssessedOn()->format('Y-m-d'), $page),
        );
    }

    public function testFindPageAppliesLimitAndOffset(): void
    {
        $repository = $this->repository();
        for ($day = 1; $day <= 4; ++$day) {
            FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable(\sprintf('2026-08-%02d', $day))]);
        }

        $page = $repository->findPage(2, 1);

        // Newest-first order is 08-04, 08-03, 08-02, 08-01.
        self::assertSame(
            ['2026-08-03', '2026-08-02'],
            array_map(static fn ($assessment): string => $assessment->getAssessedOn()->format('Y-m-d'), $page),
        );
    }

    public function testCountAllCountsEveryRowIgnoringLimitAndOffset(): void
    {
        $repository = $this->repository();
        for ($day = 1; $day <= 3; ++$day) {
            FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable(\sprintf('2026-08-%02d', $day))]);
        }

        self::assertSame(3, $repository->countAll());
    }

    public function testCountAllReturnsZeroForAnEmptyTable(): void
    {
        self::assertSame(0, $this->repository()->countAll());
    }

    public function testExistsForDateFindsAnAssessmentOnThatDay(): void
    {
        $repository = $this->repository();
        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-08')]);

        self::assertTrue($repository->existsForDate(new \DateTimeImmutable('2026-09-08')));
    }

    public function testExistsForDateIgnoresTheTimeOfDayOfTheGivenDate(): void
    {
        $repository = $this->repository();
        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-08')]);

        self::assertTrue($repository->existsForDate(new \DateTimeImmutable('2026-09-08 17:45:00')));
    }

    public function testExistsForDateIsFalseForADayWithoutAnAssessment(): void
    {
        $repository = $this->repository();
        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-08')]);

        self::assertFalse($repository->existsForDate(new \DateTimeImmutable('2026-09-09')));
    }

    private function repository(): FitnessAssessmentRepository
    {
        self::bootKernel();
        $repository = static::getContainer()->get(FitnessAssessmentRepository::class);
        \assert($repository instanceof FitnessAssessmentRepository);

        return $repository;
    }
}
