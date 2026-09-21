<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Habit;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Shared helpers of the functional tests for the habit entry endpoints
 * (T-0402, and the later habit tickets). Only what several test classes need:
 * the entry URI, "today" as the API sees it, raw request bodies for the cases
 * `apiRequest()` cannot express (empty body, broken JSON, other content
 * types), and reading the `habit_entry` table through DBAL.
 *
 * Dates are always computed relative to "today in the application timezone"
 * (`app.timezone`, the timezone the API measures "today" in), never as
 * literals, so the tests stay valid as the calendar moves on. Rows are read
 * through the plain connection, not through the entity manager: after a
 * request the manager may be closed or reset (design.md §4.2), and its
 * identity map would hide what the database really holds.
 */
abstract class HabitEntryApiTestCase extends ApiTestCase
{
    use Factories;

    protected static function entryUri(Habit|string $habit, string $date): string
    {
        $habitId = $habit instanceof Habit ? $habit->getId()->toRfc4122() : $habit;

        return \sprintf('/api/habits/%s/entries/%s', $habitId, $date);
    }

    /**
     * The calendar day `$days` from today (negative: in the past) in the application timezone, as Y-m-d.
     */
    protected static function dayFromToday(int $days): string
    {
        $timezone = static::getContainer()->getParameter('app.timezone');
        self::assertIsString($timezone);

        return (new \DateTimeImmutable('today', new \DateTimeZone($timezone)))
            ->modify(\sprintf('%+d days', $days))
            ->format('Y-m-d');
    }

    /**
     * Sends a request with a body given verbatim, authenticated with the test API key.
     */
    protected static function rawRequest(
        KernelBrowser $client,
        string $method,
        string $uri,
        string $content,
        ?string $contentType = 'application/json',
        ?string $apiKey = TEST_API_KEY,
    ): void {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $contentType) {
            $server['CONTENT_TYPE'] = $contentType;
        }
        if (null !== $apiKey) {
            $server['HTTP_X_API_KEY'] = $apiKey;
        }

        $client->request($method, $uri, [], [], $server, $content);
    }

    protected static function rawBody(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    /**
     * Empties the habit table, so a test controls the complete catalog. The
     * entry table is empty at the start of every test (rollback), so the
     * foreign key does not get in the way.
     */
    protected static function emptyHabitTable(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->createQuery('DELETE FROM App\Entity\Habit h')->execute();
    }

    protected static function connection(): Connection
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }

    protected static function entryCount(Habit $habit): int
    {
        $count = self::connection()->fetchOne('SELECT count(*) FROM habit_entry WHERE habit_id = ?', [$habit->getId()->toRfc4122()]);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<array<string, mixed>> the rows of one habit as the database holds them, oldest day first
     */
    protected static function entryRows(Habit $habit): array
    {
        return self::connection()->fetchAllAssociative(
            'SELECT id, habit_id, entry_date, value_numeric, value_bool, note, created_at FROM habit_entry WHERE habit_id = ? ORDER BY entry_date',
            [$habit->getId()->toRfc4122()],
        );
    }
}
