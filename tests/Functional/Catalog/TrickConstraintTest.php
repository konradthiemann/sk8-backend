<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Entity\TrickPrerequisite;
use App\Tests\Factory\TrickFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Verifies the database-level constraints from the schema migration
 * (`uniq_trick_slug`, `chk_trick_prerequisite_self`). Persists through raw
 * Doctrine, not the API — GET /api/tricks has no write path (see design.md).
 */
final class TrickConstraintTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testItRejectsASecondTrickWithAnAlreadyUsedSlug(): void
    {
        TrickFactory::createOne(['slug' => 'duplicate-slug']);

        $this->expectException(UniqueConstraintViolationException::class);

        TrickFactory::createOne(['slug' => 'duplicate-slug']);
    }

    public function testItRejectsAPrerequisiteThatRequiresItself(): void
    {
        $trick = TrickFactory::createOne();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->persist(new TrickPrerequisite($trick, $trick));

        $this->expectException(DriverException::class);

        $entityManager->flush();
    }
}
