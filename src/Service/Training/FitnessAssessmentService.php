<?php

declare(strict_types=1);

namespace App\Service\Training;

use App\Dto\Training\FitnessAssessmentRequest;
use App\Entity\FitnessAssessment;
use App\Repository\FitnessAssessmentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Create and delete of fitness assessments (T-0303 design.md §4). No
 * update() - there is no PUT/PATCH; a wrongly logged test is deleted and
 * re-entered.
 *
 * "One assessment per day" has two lines of defence: the existsForDate()
 * pre-check gives a clean 409 in the normal case (double click, known day)
 * without a failed SQL statement, while the unique index has the last word
 * under concurrency - check-then-insert is never atomic. Both end in the
 * same ConflictHttpException, which App\EventListener\ApiExceptionListener
 * renders as {"error":"conflict"}; a raw UniqueConstraintViolationException
 * would end as 500.
 */
final readonly class FitnessAssessmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FitnessAssessmentRepository $repository,
    ) {
    }

    /**
     * @throws ConflictHttpException an assessment for that date already exists
     */
    public function create(FitnessAssessmentRequest $request): FitnessAssessment
    {
        $assessedOn = new \DateTimeImmutable($request->assessedOn);

        if ($this->repository->existsForDate($assessedOn)) {
            throw new ConflictHttpException('An assessment for this date already exists.');
        }

        $assessment = new FitnessAssessment(
            $assessedOn,
            $request->pushUpsMax,
            $request->squatsMax,
            $request->ringPullUpsMax,
            $request->plankSeconds,
            $request->singleLegBalanceLeftSeconds,
            $request->singleLegBalanceRightSeconds,
            $request->wallSitSeconds,
            $request->standingBroadJumpCm,
            $request->notes,
        );

        $this->entityManager->persist($assessment);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // The entity manager is closed after a failed flush and must not
            // be used again in this request. The only unique index besides
            // the primary key is the one on assessed_on, so this is always
            // the duplicate day.
            throw new ConflictHttpException('An assessment for this date already exists.', $exception);
        }

        return $assessment;
    }

    public function delete(FitnessAssessment $assessment): void
    {
        $this->entityManager->remove($assessment);
        $this->entityManager->flush();
    }
}
