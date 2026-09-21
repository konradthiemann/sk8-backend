<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\HabitFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * Functional, against real Postgres (dama-rolled-back per test). The rejected
 * inputs of `PUT /api/habits/{habitId}/entries/{date}` (T-0402 design.md
 * §3.0/§3.1): ticket criteria 4, 6-10 and 19 with the exact German wording,
 * plus the framework behaviour the design measured (type errors, empty body,
 * content type) and the order of the checks.
 *
 * Every rejected request must also leave the table untouched.
 */
final class HabitEntryValidationTest extends HabitEntryApiTestCase
{
    private const string FUTURE = 'Du kannst keinen Wert für die Zukunft eintragen.';
    private const string BEFORE_START = 'Das Datum liegt vor dem Projektstart.';
    private const string INVALID_DATE = 'Das Datum muss ein gültiges Datum im Format JJJJ-MM-TT sein.';
    private const string EXACTLY_ONE = 'Gib genau einen Wert an.';

    public function testItRejectsTomorrowWithTheFutureMessage(): void
    {
        // Criterion 4.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(1)), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'date', 'message' => self::FUTURE]], self::violations(self::jsonResponse($client)));
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItRejectsAFarFutureDay(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(400)), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'date', 'message' => self::FUTURE]], self::violations(self::jsonResponse($client)));
    }

    public function testItRejectsTheDayBeforeTheProjectStart(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, '2025-12-31'), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'date', 'message' => self::BEFORE_START]], self::violations(self::jsonResponse($client)));
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItRejectsADateThatPassesTheRouteButIsNoCalendarDate(): void
    {
        // design.md §3.1: `2026-02-30` matches the route pattern, so it is a 422 on the field, not a 404.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, '2026-02-30'), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'date', 'message' => self::INVALID_DATE]], self::violations(self::jsonResponse($client)));
        self::assertSame(0, self::entryCount($habit));
    }

    #[DataProvider('malformedDates')]
    public function testItAnswers404WhenTheDateHasTheWrongShape(string $date): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, $date), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"not_found"}', self::rawBody($client));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedDates(): array
    {
        return ['unpadded' => ['2026-2-3'], 'words' => ['abc'], 'no separators' => ['20260908'], 'with a time' => ['2026-09-08T10']];
    }

    #[DataProvider('malformedHabitIds')]
    public function testItAnswers404WhenTheHabitIdIsNoLowercaseUuid(string $habitId): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'PUT', self::entryUri($habitId, self::dayFromToday(0)), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"not_found"}', self::rawBody($client));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedHabitIds(): array
    {
        return [
            'words' => ['not-a-uuid'],
            'number' => ['42'],
            'uppercase uuid' => [strtoupper('0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d')],
        ];
    }

    public function testItAnswers404WithoutAnApiKeyWhenTheRouteDoesNotMatch(): void
    {
        // design.md §3.0: the router runs before the firewall.
        $client = static::createClient();

        self::apiRequest($client, 'PUT', self::entryUri('not-a-uuid', self::dayFromToday(0)), ['valueNumeric' => 7.5], apiKey: null);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"not_found"}', self::rawBody($client));
    }

    public function testItRejectsANumberForAYesNoHabit(): void
    {
        // Criterion 6.
        $client = static::createClient();
        $habit = HabitFactory::new()->boolean()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 1]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'valueNumeric', 'message' => 'Hier wird Ja oder Nein erwartet.']], self::violations(self::jsonResponse($client)));
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItRejectsAYesNoValueForANumericHabit(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueBool' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'valueBool', 'message' => 'Hier wird eine Zahl erwartet.']], self::violations(self::jsonResponse($client)));
    }

    public function testItRejectsAValueAboveTheScaleAndNamesTheRange(): void
    {
        // Criterion 7.
        $client = static::createClient();
        $habit = HabitFactory::new()->scale()->create(['scaleMin' => 0, 'scaleMax' => 10]);

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 11]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'valueNumeric', 'message' => 'Der Wert muss eine ganze Zahl zwischen 0 und 10 sein.']],
            self::violations(self::jsonResponse($client)),
        );
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItRejectsAFractionOnAScale(): void
    {
        // Criterion 8.
        $client = static::createClient();
        $habit = HabitFactory::new()->scale()->create(['scaleMin' => 0, 'scaleMax' => 10]);

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 3.5]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'valueNumeric', 'message' => 'Der Wert muss eine ganze Zahl zwischen 0 und 10 sein.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItUsesTheBoundsOfTheHabitInTheScaleMessage(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->scale()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 0]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'valueNumeric', 'message' => 'Der Wert muss eine ganze Zahl zwischen 1 und 5 sein.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItRejectsHoursAboveADay(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 24.25]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'valueNumeric', 'message' => 'Der Wert muss zwischen 0 und 24 liegen.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItRejectsHoursOffTheQuarterHourStep(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.3]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'valueNumeric', 'message' => 'Der Wert hat eine ungültige Schrittweite; erlaubt sind Vielfache von 0,25.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItRejectsACountAboveTheMaximum(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->number()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 1000.01]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'valueNumeric', 'message' => 'Der Wert muss zwischen 0 und 1000 liegen.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItRejectsACountWithThreeDecimals(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->number()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 1.005]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'valueNumeric', 'message' => 'Der Wert hat eine ungültige Schrittweite; erlaubt sind Vielfache von 0,01.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItRejectsAnInfiniteNumberFromTheJsonBody(): void
    {
        // design.md §3.1: the serializer turns 1e999 into INF; the validator must treat it as out of range.
        $client = static::createClient();
        $habit = HabitFactory::new()->number()->create();

        self::rawRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), '{"valueNumeric":1e999}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['valueNumeric'], self::violationFields(self::jsonResponse($client)));
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItRejectsBothValues(): void
    {
        // Criterion 9.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7, 'valueBool' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'valueNumeric', 'message' => self::EXACTLY_ONE]], self::violations(self::jsonResponse($client)));
        self::assertSame(0, self::entryCount($habit));
    }

    #[DataProvider('bodiesWithoutAValue')]
    public function testItRejectsABodyWithoutAnyValue(string $body): void
    {
        // Criterion 10, and the shapes design.md §3.1 measured to end up here too.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::rawRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'valueNumeric', 'message' => self::EXACTLY_ONE]], self::violations(self::jsonResponse($client)));
        self::assertSame(0, self::entryCount($habit));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesWithoutAValue(): array
    {
        return [
            'empty object' => ['{}'],
            'json null' => ['null'],
            'empty list' => ['[]'],
            'list with a number' => ['[1]'],
            'unknown fields only' => ['{"foo":1}'],
            'only a note' => ['{"note":"nur eine Notiz"}'],
        ];
    }

    public function testItAcceptsANoteOfExactly500Characters(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5, 'note' => str_repeat('ä', 500)]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(str_repeat('ä', 500), self::jsonResponse($client)['note'] ?? null);
    }

    public function testItRejectsANoteOf501CharactersAndNamesTheLimit(): void
    {
        // Criterion 19: the limit counts characters, not bytes.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5, 'note' => str_repeat('ä', 501)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'note', 'message' => 'Die Notiz darf höchstens 500 Zeichen lang sein.']],
            self::violations(self::jsonResponse($client)),
        );
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItReportsTheDateAndTheValueViolationTogetherDateFirst(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(1)), ['valueNumeric' => 24.25]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [
                ['field' => 'date', 'message' => self::FUTURE],
                ['field' => 'valueNumeric', 'message' => 'Der Wert muss zwischen 0 und 24 liegen.'],
            ],
            self::violations(self::jsonResponse($client)),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('wronglyTypedPayloads')]
    public function testItRejectsAWronglyTypedFieldWithoutConvertingIt(array $payload, string $field): void
    {
        // design.md §3.1: the serializer does not coerce, "7.5" is not 7.5 and 1 is not true.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($field, self::violationFields(self::jsonResponse($client)));
        self::assertSame(0, self::entryCount($habit));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function wronglyTypedPayloads(): array
    {
        return [
            'number as string' => [['valueNumeric' => '7.5'], 'valueNumeric'],
            'words as number' => [['valueNumeric' => 'abc'], 'valueNumeric'],
            'true as number' => [['valueNumeric' => true], 'valueNumeric'],
            'list as number' => [['valueNumeric' => [1]], 'valueNumeric'],
            'one as yes' => [['valueBool' => 1], 'valueBool'],
            'zero as no' => [['valueBool' => 0], 'valueBool'],
            'true as string' => [['valueBool' => 'true'], 'valueBool'],
            'words as yes' => [['valueBool' => 'ja'], 'valueBool'],
            'number as note' => [['valueNumeric' => 7.5, 'note' => 123], 'note'],
        ];
    }

    public function testItAnswers400ForAnEmptyBody(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::rawRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), '');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('{"error":"bad_request"}', self::rawBody($client));
    }

    public function testItAnswers400ForBrokenJson(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::rawRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), '{"valueNumeric": 7.');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('{"error":"bad_request"}', self::rawBody($client));
    }

    public function testItAnswers415ForAPlainTextBody(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::rawRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), '{"valueNumeric":7.5}', 'text/plain');

        self::assertResponseStatusCodeSame(415);
        self::assertSame('{"error":"unsupported_media_type"}', self::rawBody($client));
        self::assertSame(0, self::entryCount($habit));
    }

    #[DataProvider('unsupportedMethods')]
    public function testItRejectsEveryMethodOtherThanPutAndDeleteOnTheEntryUri(string $method): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, $method, self::entryUri($habit, self::dayFromToday(0)));

        self::assertResponseStatusCodeSame(405);
        self::assertSame(['error' => 'method_not_allowed'], self::jsonResponse($client));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedMethods(): array
    {
        return ['GET' => ['GET'], 'POST' => ['POST'], 'PATCH' => ['PATCH']];
    }

    public function testItReportsAnUnknownHabitBeforeADateOrValueViolation(): void
    {
        // design.md §3.0: the habit is looked up before the date and the value are checked.
        $client = static::createClient();

        self::apiRequest($client, 'PUT', self::entryUri(Uuid::v7()->toRfc4122(), self::dayFromToday(1)), ['valueNumeric' => 24.25]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_not_found"}', self::rawBody($client));
    }

    public function testItReportsAnInactiveHabitBeforeAValueViolation(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->inactive()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 24.25]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_not_found"}', self::rawBody($client));
    }

    public function testItChecksTheBodyShapeBeforeTheHabit(): void
    {
        // design.md §3.0: the payload resolver (here: a note that is too long) runs before the habit lookup.
        $client = static::createClient();

        self::apiRequest($client, 'PUT', self::entryUri(Uuid::v7()->toRfc4122(), self::dayFromToday(0)), ['valueNumeric' => 7.5, 'note' => str_repeat('a', 501)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['note'], self::violationFields(self::jsonResponse($client)));
    }
}
