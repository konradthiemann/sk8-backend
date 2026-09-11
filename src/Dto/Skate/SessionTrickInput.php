<?php

declare(strict_types=1);

namespace App\Dto\Skate;

use App\Validator\ExistingTrickSlug;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One practiced-trick row inside SkateSessionRequest.tricks.
 */
#[OA\Schema(required: ['trickSlug', 'attempts', 'landed'])]
final readonly class SessionTrickInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'skate_session.trick.slug.blank')]
        #[ExistingTrickSlug(message: 'skate_session.trick.slug.unknown')]
        #[OA\Property(description: 'Slug of a catalog trick (T-0101)', example: 'ollie')]
        public string $trickSlug,

        #[Assert\Range(min: 1, max: 999, notInRangeMessage: 'skate_session.trick.attempts.range')]
        #[OA\Property(description: 'Number of attempts, 1 to 999', example: 30)]
        public int $attempts,

        #[Assert\GreaterThanOrEqual(value: 0, message: 'skate_session.trick.landed.range')]
        #[Assert\LessThanOrEqual(propertyPath: 'attempts', message: 'skate_session.trick.landed.above_attempts')]
        #[OA\Property(description: 'Number of landed attempts, 0 to attempts', example: 21)]
        public int $landed,

        #[Assert\Length(max: 500, maxMessage: 'skate_session.trick.notes.too_long')]
        #[OA\Property(description: 'Optional note for this trick row', example: 'Zu frueh aufgesetzt.', nullable: true)]
        public ?string $notes = null,
    ) {
    }
}
