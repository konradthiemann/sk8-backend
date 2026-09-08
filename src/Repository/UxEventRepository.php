<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UxEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UxEvent>
 */
final class UxEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UxEvent::class);
    }

    /**
     * All events of one frontend session, oldest first.
     *
     * @return list<UxEvent>
     */
    public function findBySession(Uuid $sessionId): array
    {
        return $this->findBy(['sessionId' => $sessionId], ['occurredAt' => 'ASC', 'id' => 'ASC']);
    }
}
