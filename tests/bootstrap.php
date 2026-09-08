<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if (filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL)) {
    umask(0000);
}

// Value sent as X-Api-Key by the functional tests. It mirrors APP_API_KEY of
// the test environment (.env.test) so both sides always agree.
$apiKey = $_SERVER['APP_API_KEY'] ?? null;
define('TEST_API_KEY', is_string($apiKey) && '' !== $apiKey ? $apiKey : 'test-key');
