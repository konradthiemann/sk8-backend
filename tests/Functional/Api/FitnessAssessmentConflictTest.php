<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\FitnessAssessment;
use App\Tests\Factory\FitnessAssessmentFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\Common\EventArgs;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The second line of defence for "one assessment per day": the service
 * checks `existsForDate()` first, but check-then-insert is never atomic, so
 * `uniq_fitness_assessment_assessed_on` has the last word. These tests prove
 * (a) that the index exists and fires, and (b) that a violation which slips
 * past the service check ends as 409, not as a 500.
 *
 * The service check cannot be switched off from a functional test without
 * bending production code (the repository is `final`, and compiled service
 * aliases cannot be replaced at runtime). Instead the race is replayed
 * deterministically: a `prePersist` listener inserts a row for the very same
 * day through the raw connection. `existsForDate()` has already answered
 * "free" by then (it runs before `persist()`), so the `flush()` collides
 * with the row the listener slipped in.
 */
final class FitnessAssessmentConflictTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/fitness-assessments';

    public function testItRejectsASecondRowForTheSameDayAtTheDatabaseLevel(): void
    {
        // Foundry needs the kernel booted before it can persist, so
        // createClient() (which owns the boot) always comes first.
        static::createClient();

        $sameDay = new \DateTimeImmutable('2026-09-08');
        FitnessAssessmentFactory::createOne(['assessedOn' => $sameDay]);

        try {
            FitnessAssessmentFactory::createOne(['assessedOn' => $sameDay]);
            self::fail('expected uniq_fitness_assessment_assessed_on to reject a second row for the same day');
        } catch (UniqueConstraintViolationException $exception) {
            // Asserting the index name keeps this red until the right index
            // genuinely exists: any other unique violation (e.g. the primary
            // key) would otherwise satisfy a bare expectException().
            self::assertStringContainsString('uniq_fitness_assessment_assessed_on', $exception->getMessage());
        }
    }

    public function testItTurnsAUniqueViolationThatSlipsPastTheServiceCheckIntoConflict(): void
    {
        // Exactly one request per kernel: from the second request on, the
        // KernelBrowser reboots the kernel and the listener below would be
        // gone.
        $client = static::createClient();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $racingRowId = Uuid::v7()->toRfc4122();
        $entityManager->getEventManager()->addEventListener(
            [Events::prePersist],
            new class($entityManager, $racingRowId) {
                private bool $fired = false;

                public function __construct(
                    private readonly EntityManagerInterface $entityManager,
                    private readonly string $rowId,
                ) {
                }

                public function prePersist(EventArgs $args): void
                {
                    if ($this->fired || !$args instanceof PrePersistEventArgs || !$args->getObject() instanceof FitnessAssessment) {
                        return;
                    }

                    $this->fired = true;
                    $this->entityManager->getConnection()->executeStatement(
                        'INSERT INTO fitness_assessment (id, assessed_on, plank_seconds) VALUES (?, ?, ?)',
                        [$this->rowId, '2026-09-08', 60],
                    );
                }
            },
        );

        self::apiRequest($client, 'POST', self::ENDPOINT, ['assessedOn' => '2026-09-08', 'pushUpsMax' => 24]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'conflict'], self::jsonResponse($client));

        // The entity manager is closed after the failed flush, so the
        // aftermath is read through the plain connection.
        $connection = $entityManager->getConnection();
        self::assertSame(
            [$racingRowId],
            $connection->fetchFirstColumn('SELECT id FROM fitness_assessment WHERE assessed_on = ?', ['2026-09-08']),
            'only the row the listener slipped in may exist for that day',
        );
    }
}
