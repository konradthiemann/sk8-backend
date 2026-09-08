<?php

declare(strict_types=1);

// Entry point for phpstan-doctrine: exposes the entity manager so the
// extension can read the real entity metadata (no database connection needed).

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('test', true);
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
assert($doctrine instanceof ManagerRegistry);

$entityManager = $doctrine->getManager();
assert($entityManager instanceof EntityManagerInterface);

return $entityManager;
