<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Body;

use App\Entity\BodyWeight;
use App\Entity\SkateSession;
use App\Enum\BodyWeightContext;
use App\Service\Body\BodyWeightSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP object test, no kernel, no database (design.md §4:
 * "kernelfrei testbare Unit"). `App\Entity\SkateSession` is a plain
 * constructor-built object, no Doctrine proxy involved, so it can be built
 * directly here the same way SkateSessionFactory would, minus persistence.
 *
 * `App\Repository\BodyWeightRepositoryInterface` (backed here by
 * InMemoryBodyWeightRepository.php) is this test's own addition, not part of
 * design.md - see that file's doc comment and tests.md, "Abweichung von
 * design.md §4" for why `BodyWeightSynchronizer` is expected to depend on it
 * instead of the concrete, presumably `final` `BodyWeightRepository`.
 *
 * `Doctrine\ORM\EntityManagerInterface` is stubbed directly via PHPUnit
 * (`self::createStub()`), matching the established pattern of this codebase
 * for that specific interface (see
 * tests/Unit/Service/Telemetry/TelemetryIngestServiceTest.php) - it is a
 * public Doctrine seam meant to be substituted, not a concrete/final
 * "Doctrine-Interna" the project's testing rule warns against.
 */
final class BodyWeightSynchronizerTest extends TestCase
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function testItPlacesBothRowsAtSessionDateNoonInAppTimezoneWhenStartedAtIsMissing(): void
    {
        // Criterion 2: no startedAt, sessionDate 2026-09-06, 60 minutes ->
        // vor_session at 06.09. 12:00 Europe/Berlin, nach_session at 13:00
        // Europe/Berlin (design.md §2, "Verifikation gegen AK 1/2").
        $session = $this->session(
            sessionDate: '2026-09-06',
            startedAt: null,
            durationMinutes: 60,
            weightBeforeKg: '78.40',
            weightAfterKg: '77.10',
        );
        $repository = new InMemoryBodyWeightRepository();
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        $synchronizer = new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE);
        $synchronizer->sync($session);

        self::assertCount(2, $persisted);
        [$before, $after] = $this->byContext($persisted);

        self::assertSame('2026-09-06T12:00:00+02:00', $before->getMeasuredAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-09-06T13:00:00+02:00', $after->getMeasuredAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-09-06', $before->getMeasuredOn()->format('Y-m-d'));
        self::assertSame('2026-09-06', $after->getMeasuredOn()->format('Y-m-d'));
        self::assertSame('78.40', $before->getWeightKg());
        self::assertSame('77.10', $after->getWeightKg());
    }

    public function testItUsesStartedAtDirectlyAsTheBaseTimestampWhenPresent(): void
    {
        // Supports criterion 1's derivation algorithm (criterion 1 itself is
        // proven end-to-end via
        // tests/Functional/Api/SkateSessionBodyWeightTest.php, which is the
        // only way to see the response envelope + persisted rows together).
        // Same numbers as design.md's "Verifikation gegen AK 1/2":
        // startedAt 16:30+02:00, duration 95 minutes -> vor_session at
        // 16:30+02:00 (= 14:30 UTC), nach_session at 18:05+02:00 (= 16:05 UTC).
        $session = $this->session(
            sessionDate: '2026-09-06',
            startedAt: '2026-09-06T16:30:00+02:00',
            durationMinutes: 95,
            weightBeforeKg: '78.40',
            weightAfterKg: '77.10',
        );
        $repository = new InMemoryBodyWeightRepository();
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        (new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE))->sync($session);

        [$before, $after] = $this->byContext($persisted);
        self::assertSame('2026-09-06T16:30:00+02:00', $before->getMeasuredAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-09-06T18:05:00+02:00', $after->getMeasuredAt()->format(\DateTimeInterface::ATOM));
        self::assertSame(
            '2026-09-06T16:05:00+00:00',
            $after->getMeasuredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );
    }

    public function testItKeepsMeasuredOnAtTheSessionDateEvenWhenTheAfterSessionTimestampCrossesMidnight(): void
    {
        // design.md §2: "measured_on ... auch wenn nach_session ueber
        // Mitternacht rutscht" - an evening session (22:30, 105 minutes)
        // pushes nach_session's clock time past midnight into 2026-09-07,
        // but measured_on for both rows must stay on the session's own date.
        $session = $this->session(
            sessionDate: '2026-09-06',
            startedAt: '2026-09-06T22:30:00+02:00',
            durationMinutes: 105,
            weightBeforeKg: '80.00',
            weightAfterKg: '79.00',
        );
        $repository = new InMemoryBodyWeightRepository();
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        (new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE))->sync($session);

        [$before, $after] = $this->byContext($persisted);
        self::assertSame('2026-09-07T00:15:00+02:00', $after->getMeasuredAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-09-06', $after->getMeasuredOn()->format('Y-m-d'), 'measured_on must not roll over to the next calendar day');
        self::assertSame('2026-09-06', $before->getMeasuredOn()->format('Y-m-d'));
    }

    public function testItCreatesOnlyTheBeforeSessionRowWhenOnlyWeightBeforeIsSet(): void
    {
        // Criterion 3.
        $session = $this->session(
            sessionDate: '2026-09-06',
            startedAt: null,
            durationMinutes: 60,
            weightBeforeKg: '78.40',
            weightAfterKg: null,
        );
        $repository = new InMemoryBodyWeightRepository();
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        (new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE))->sync($session);

        self::assertCount(1, $persisted);
        self::assertSame(BodyWeightContext::BeforeSession, $persisted[0]->getContext());
        self::assertSame('78.40', $persisted[0]->getWeightKg());
        self::assertSame([], $removed, 'nothing existed for nach_session, so nothing should be removed');
    }

    public function testItCreatesOnlyTheAfterSessionRowWhenOnlyWeightAfterIsSet(): void
    {
        // Mirrors criterion 3 for the after_session context: the two
        // contexts are synced independently of each other (design.md §2,
        // "Nur-Anlegen-wenn-Gewicht-gesetzt").
        $session = $this->session(
            sessionDate: '2026-09-06',
            startedAt: null,
            durationMinutes: 60,
            weightBeforeKg: null,
            weightAfterKg: '77.10',
        );
        $repository = new InMemoryBodyWeightRepository();
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        (new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE))->sync($session);

        self::assertCount(1, $persisted);
        self::assertSame(BodyWeightContext::AfterSession, $persisted[0]->getContext());
        self::assertSame('77.10', $persisted[0]->getWeightKg());
    }

    public function testItCreatesNoRowsWhenNeitherWeightIsSet(): void
    {
        // Criterion 4.
        $session = $this->session(
            sessionDate: '2026-09-06',
            startedAt: null,
            durationMinutes: 60,
            weightBeforeKg: null,
            weightAfterKg: null,
        );
        $repository = new InMemoryBodyWeightRepository();
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        (new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE))->sync($session);

        self::assertSame([], $persisted);
        self::assertSame([], $removed);
    }

    public function testItUpdatesAnExistingRowsMeasuredAtMeasuredOnAndWeightInPlaceKeepingItsIdentity(): void
    {
        // Supports criterion 5 (the end-to-end id-preservation contract is
        // proven via the functional API test, which is the only place that
        // can observe the returned row's JSON "id"). Isolated here to the
        // nach_session row only: weightBeforeKg stays null throughout, so the
        // vor_session branch is a no-op and does not interfere.
        $session = $this->session('2026-09-01', null, 60, null, '77.10');
        $existing = new BodyWeight(
            $session->getSessionDate(),
            $session->getSessionDate()->setTime(12, 0),
            '77.10',
            BodyWeightContext::AfterSession,
            $session,
        );
        $repository = new InMemoryBodyWeightRepository();
        $repository->rows[] = $existing;
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        // Simulates a PUT that only changes weightAfterKg: same session
        // object (same id), field mutated via its existing setter.
        $session->setWeightAfterKg('76.80');

        (new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE))->sync($session);

        self::assertSame('76.80', $existing->getWeightKg());
        self::assertSame('2026-09-01T13:00:00+02:00', $existing->getMeasuredAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-09-01', $existing->getMeasuredOn()->format('Y-m-d'));
        self::assertSame([], $persisted, 'an update mutates the existing managed row, it does not persist a new one');
    }

    public function testItRemovesAnExistingRowWhenItsWeightBecomesNull(): void
    {
        // Supports criterion 6 (end-to-end proof via the functional API
        // test).
        $session = $this->session('2026-09-01', null, 60, null, null);
        $existing = new BodyWeight($session->getSessionDate(), $session->getSessionDate()->setTime(12, 0), '77.10', BodyWeightContext::AfterSession, $session);
        $repository = new InMemoryBodyWeightRepository();
        $repository->rows[] = $existing;
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        (new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE))->sync($session);

        self::assertSame([$existing], $removed);
        self::assertSame([], $persisted);
    }

    public function testRemoveForSessionRemovesEveryExistingRowForTheSession(): void
    {
        // BodyWeightSynchronizer's second method (design.md §4), the
        // building block SkateSessionService::delete() is expected to call.
        // End-to-end proof of the DELETE flow itself (criterion 9) is
        // tests/Functional/Api/SkateSessionBodyWeightTest.php.
        $session = $this->session('2026-09-01', null, 60, '78.40', '77.10');
        $before = new BodyWeight($session->getSessionDate(), $session->getSessionDate()->setTime(12, 0), '78.40', BodyWeightContext::BeforeSession, $session);
        $after = new BodyWeight($session->getSessionDate(), $session->getSessionDate()->setTime(13, 0), '77.10', BodyWeightContext::AfterSession, $session);
        $repository = new InMemoryBodyWeightRepository();
        $repository->rows = [$before, $after];
        $persisted = [];
        $removed = [];
        $entityManager = $this->stubEntityManager($persisted, $removed);

        (new BodyWeightSynchronizer($repository, $entityManager, self::TIMEZONE))->removeForSession($session);

        self::assertCount(2, $removed);
        self::assertContains($before, $removed);
        self::assertContains($after, $removed);
    }

    private function session(
        string $sessionDate,
        ?string $startedAt,
        int $durationMinutes,
        ?string $weightBeforeKg,
        ?string $weightAfterKg,
    ): SkateSession {
        return new SkateSession(
            new \DateTimeImmutable($sessionDate),
            null === $startedAt ? null : new \DateTimeImmutable($startedAt),
            $durationMinutes,
            'Skatepark Braunschweig',
            $weightBeforeKg,
            $weightAfterKg,
            null,
            null,
            null,
            new \DateTimeImmutable(),
        );
    }

    /**
     * @param list<object> $persisted
     * @param list<object> $removed
     */
    /**
     * @param list<BodyWeight> $persisted
     * @param list<BodyWeight> $removed
     */
    private function stubEntityManager(array &$persisted, array &$removed): EntityManagerInterface
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            // The synchronizer only ever manages BodyWeight rows; asserting
            // that here also narrows $entity for PHPStan, which otherwise
            // only sees persist()'s generic `object` parameter type.
            self::assertInstanceOf(BodyWeight::class, $entity);
            $persisted[] = $entity;
        });
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            self::assertInstanceOf(BodyWeight::class, $entity);
            $removed[] = $entity;
        });

        return $entityManager;
    }

    /**
     * @param list<BodyWeight> $rows
     *
     * @return array{0: BodyWeight, 1: BodyWeight}
     */
    private function byContext(array $rows): array
    {
        $before = null;
        $after = null;
        foreach ($rows as $row) {
            if (BodyWeightContext::BeforeSession === $row->getContext()) {
                $before = $row;
            }
            if (BodyWeightContext::AfterSession === $row->getContext()) {
                $after = $row;
            }
        }

        self::assertNotNull($before, 'expected a vor_session row');
        self::assertNotNull($after, 'expected a nach_session row');

        return [$before, $after];
    }
}
