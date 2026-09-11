<?php

declare(strict_types=1);

namespace App\Tests\Functional\Body;

use App\Entity\BodyWeight;
use App\Enum\BodyWeightContext;
use App\Tests\Factory\BodyWeightFactory;
use App\Tests\Factory\SkateSessionFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Verifies the database-level constraints from the schema migration
 * (`uniq_body_weight_session_context`, `chk_body_weight_context`) - the same
 * pattern as tests/Functional/Catalog/TrickConstraintTest.php for T-0101's
 * `chk_trick_prerequisite_self`. Persists through raw Doctrine/DBAL, not the
 * API: there is no write endpoint for body_weight (design.md §3).
 */
final class BodyWeightConstraintTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testItAllowsTwoStandaloneMorningRowsOnTheSameDayWithoutASession(): void
    {
        // Criterion 11: the partial unique index only covers
        // skate_session_id IS NOT NULL, so two context=morgens rows with no
        // session must not collide even on the same measured_on.
        $sameDay = new \DateTimeImmutable('2026-09-06');

        BodyWeightFactory::createOne(['context' => BodyWeightContext::Morning, 'skateSession' => null, 'measuredOn' => $sameDay]);
        BodyWeightFactory::createOne(['context' => BodyWeightContext::Morning, 'skateSession' => null, 'measuredOn' => $sameDay]);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = $entityManager->getRepository(BodyWeight::class);

        self::assertSame(2, $repository->count(['context' => BodyWeightContext::Morning]));
    }

    public function testItRejectsASecondRowWithTheSameSessionAndContext(): void
    {
        // Criterion 12.
        $session = SkateSessionFactory::createOne();
        BodyWeightFactory::createOne(['context' => BodyWeightContext::BeforeSession, 'skateSession' => $session]);

        $this->expectException(UniqueConstraintViolationException::class);

        BodyWeightFactory::createOne(['context' => BodyWeightContext::BeforeSession, 'skateSession' => $session]);
    }

    public function testItRejectsAContextOutsideTheFourAllowedValues(): void
    {
        // Criterion 13. Bypasses the App\Enum\BodyWeightContext backed enum
        // entirely (it cannot represent an invalid value in the first
        // place) to exercise chk_body_weight_context directly, the same way
        // TrickConstraintTest exercises chk_trick_prerequisite_self via a
        // deliberately invalid entity graph rather than PHP-level
        // validation.
        //
        // Deliberately not `expectException(DriverException::class)`
        // (TrickConstraintTest's pattern for its CHECK constraint): Postgres
        // has no dedicated exception subtype for a CHECK violation, and
        // DriverException is also the parent of TableNotFoundException - a
        // bare expectException() would report this test green already while
        // the body_weight table itself does not exist yet, for entirely the
        // wrong reason. Asserting the constraint name in the message keeps
        // this red until chk_body_weight_context genuinely exists and fires.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        try {
            $connection->executeStatement(
                'INSERT INTO body_weight (id, measured_on, measured_at, weight_kg, context, skate_session_id) VALUES (?, ?, ?, ?, ?, NULL)',
                [Uuid::v7()->toRfc4122(), '2026-09-06', '2026-09-06 12:00:00+02', '75.00', 'invalid-context'],
                ['string', 'string', 'string', 'string', 'string'],
            );
            self::fail('expected chk_body_weight_context to reject an unknown context value');
        } catch (DriverException $exception) {
            self::assertStringContainsString('chk_body_weight_context', $exception->getMessage());
        }
    }
}
