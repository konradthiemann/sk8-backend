<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use App\Entity\Trick;
use App\Entity\TrickPrerequisite;
use OpenApi\Attributes as OA;

/**
 * One entry of GET /api/tricks.
 */
final readonly class TrickResponse
{
    public function __construct(
        #[OA\Property(description: 'Trick ID', example: '01997c6a-3b21-7c4e-9a10-4f2b6d8e1c35')]
        public string $id,
        #[OA\Property(description: 'Unique, stable slug', example: 'ollie')]
        public string $slug,
        #[OA\Property(description: 'Display name (German)', example: 'Ollie')]
        public string $name,
        #[OA\Property(description: 'Movement category', example: 'flat')]
        public string $category,
        #[OA\Property(description: 'Position in the tree, 1 to 10', example: 3)]
        public int $difficulty,
        #[OA\Property(description: 'Optional description', example: 'Ollie in der Fahrt. Ausgangsniveau etwa 25 cm, Zielhöhe darüber.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'Whether this trick is one of the seven contest goals', example: true)]
        public bool $isGoal,
        #[OA\Property(description: 'Position among the seven goals, 1 to 7', example: 1, nullable: true)]
        public ?int $goalOrder,
        /**
         * @var list<string>
         */
        #[OA\Property(description: 'Slugs of the direct prerequisites, alphabetically sorted', type: 'array', items: new OA\Items(type: 'string'), example: ['ollie-stand'])]
        public array $prerequisiteSlugs,
    ) {
    }

    public static function fromEntity(Trick $trick): self
    {
        $prerequisiteSlugs = array_map(
            static fn (TrickPrerequisite $prerequisite): string => $prerequisite->getRequiresTrick()->getSlug(),
            $trick->getPrerequisites()->toArray(),
        );
        sort($prerequisiteSlugs, \SORT_STRING);

        return new self(
            $trick->getId()->toRfc4122(),
            $trick->getSlug(),
            $trick->getName(),
            $trick->getCategory()->value,
            $trick->getDifficulty(),
            $trick->getDescription(),
            $trick->isGoal(),
            $trick->getGoalOrder(),
            $prerequisiteSlugs,
        );
    }
}
