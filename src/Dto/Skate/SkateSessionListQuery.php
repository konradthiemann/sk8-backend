<?php

declare(strict_types=1);

namespace App\Dto\Skate;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query parameters of GET /api/skate-sessions (#[MapQueryString]).
 */
final readonly class SkateSessionListQuery
{
    public function __construct(
        #[Assert\Date(message: 'skate_session.list.from.invalid')]
        #[OA\Property(description: 'Only sessions with sessionDate >= from', format: 'date', example: '2026-09-01', nullable: true)]
        public ?string $from = null,

        #[Assert\Date(message: 'skate_session.list.to.invalid')]
        #[OA\Property(description: 'Only sessions with sessionDate <= to', format: 'date', example: '2026-09-03', nullable: true)]
        public ?string $to = null,

        #[Assert\Range(min: 1, max: 200, notInRangeMessage: 'skate_session.list.limit.range')]
        #[OA\Property(description: 'Maximum number of items, 1 to 200', example: 50)]
        public int $limit = 50,
    ) {
    }

    /**
     * Criterion 21: when both boundaries are given, from must not be after to.
     */
    #[Assert\Callback]
    public function validateFromNotAfterTo(ExecutionContextInterface $context): void
    {
        if (null === $this->from || null === $this->to) {
            return;
        }

        try {
            $from = new \DateTimeImmutable($this->from);
            $to = new \DateTimeImmutable($this->to);
        } catch (\Exception) {
            // Malformed value: Assert\Date already reports this per field.
            return;
        }

        if ($from > $to) {
            $context->buildViolation('skate_session.list.from_after_to')
                ->atPath('from')
                ->addViolation();
        }
    }
}
