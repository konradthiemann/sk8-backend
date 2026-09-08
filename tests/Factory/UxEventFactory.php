<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\UxEvent;
use App\Enum\TelemetryApp;
use App\Enum\UxEventType;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<UxEvent>
 */
final class UxEventFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return UxEvent::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $occurredAt = \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-1 hour', 'now', 'UTC'));

        return [
            'app' => self::faker()->randomElement(TelemetryApp::cases()),
            'sessionId' => Uuid::v4(),
            'type' => self::faker()->randomElement(UxEventType::cases()),
            'screen' => self::faker()->randomElement(['home', 'sessions', 'spots', 'settings']),
            'target' => null,
            'meta' => [],
            'occurredAt' => $occurredAt,
            'receivedAt' => $occurredAt->modify('+2 seconds'),
        ];
    }
}
