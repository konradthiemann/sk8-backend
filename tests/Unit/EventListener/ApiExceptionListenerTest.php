<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\ApiExceptionListener;
use App\Exception\FieldViolationsException;
use App\Exception\HabitEntryNotFoundException;
use App\Exception\HabitNotFoundException;
use App\Exception\ProvidesApiErrorCode;
use App\Service\Habit\FieldViolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pure object test, no kernel: the listener is called with a hand-built
 * `ExceptionEvent`. Covers T-0402 design.md §3.0: the listener derives the
 * error code from the status code alone, so the two domain 404s bring their
 * own code through `ProvidesApiErrorCode`, and a 422 raised outside a DTO
 * (`FieldViolationsException`) has to come out with the same `violations`
 * list as a DTO validation error.
 */
final class ApiExceptionListenerTest extends TestCase
{
    public function testItAnswersAnUnknownHabitWithItsOwnErrorCode(): void
    {
        $response = $this->handle(new HabitNotFoundException());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(['error' => 'habit_not_found'], $this->body($response));
    }

    public function testItAnswersAMissingEntryWithItsOwnErrorCode(): void
    {
        $response = $this->handle(new HabitEntryNotFoundException());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(['error' => 'habit_entry_not_found'], $this->body($response));
    }

    public function testItKeepsTheGenericCodeForAPlainNotFound(): void
    {
        $response = $this->handle(new NotFoundHttpException('no route'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(['error' => 'not_found'], $this->body($response));
    }

    public function testItExposesTheCodesOnTheExceptionsThemselves(): void
    {
        $habit = new HabitNotFoundException();
        $entry = new HabitEntryNotFoundException();

        foreach ([HabitNotFoundException::class, HabitEntryNotFoundException::class] as $class) {
            $implemented = class_implements($class);
            self::assertIsArray($implemented);
            self::assertContains(ProvidesApiErrorCode::class, $implemented, "{$class} must provide its error code");
            self::assertSame(Response::HTTP_NOT_FOUND, (new $class())->getStatusCode(), "{$class} must stay a 404");
        }
        self::assertSame('habit_not_found', $habit->getApiErrorCode());
        self::assertSame('habit_entry_not_found', $entry->getApiErrorCode());
    }

    public function testItTakesTheCodeFromAnyExceptionThatProvidesOne(): void
    {
        // The listener stays generic: it must not know the domain classes.
        $exception = new class('custom') extends NotFoundHttpException implements ProvidesApiErrorCode {
            public function getApiErrorCode(): string
            {
                return 'custom_code';
            }
        };

        self::assertSame(['error' => 'custom_code'], $this->body($this->handle($exception)));
    }

    public function testItAnswersFieldViolationsWithTheSharedValidationEnvelope(): void
    {
        $exception = FieldViolationsException::create(
            [new FieldViolation('valueNumeric', 'habit_entry.value.out_of_scale', ['min' => 0, 'max' => 10])],
            $this->translator(),
        );

        $response = $this->handle($exception);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(
            [
                'error' => 'validation_failed',
                'violations' => [
                    ['field' => 'valueNumeric', 'message' => 'Der Wert muss eine ganze Zahl zwischen 0 und 10 sein.'],
                ],
            ],
            $this->body($response),
        );
    }

    public function testItKeepsAllViolationsInTheirOrder(): void
    {
        $exception = FieldViolationsException::create(
            [
                new FieldViolation('date', 'habit_entry.date.future'),
                new FieldViolation('valueNumeric', 'habit_entry.value.exactly_one'),
            ],
            $this->translator(),
        );

        $body = $this->body($this->handle($exception));

        self::assertSame(
            [
                ['field' => 'date', 'message' => 'Du kannst keinen Wert für die Zukunft eintragen.'],
                ['field' => 'valueNumeric', 'message' => 'Gib genau einen Wert an.'],
            ],
            $body['violations'] ?? null,
        );
    }

    public function testItHandsTheViolationsOverThroughGetFieldViolations(): void
    {
        $violations = [
            new FieldViolation('date', 'habit_entry.date.invalid'),
            new FieldViolation('valueBool', 'habit_entry.value.numeric_expected'),
        ];

        $exception = FieldViolationsException::create($violations, $this->translator());

        self::assertEquals($violations, $exception->getFieldViolations());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getStatusCode());
    }

    public function testItStillAnswersAnUnexpectedExceptionAsAnInternalError(): void
    {
        // An unknown duration unit is a catalog error, not a user error (design.md §3.1).
        $response = $this->handle(new \LogicException('unknown unit'));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame(['error' => 'internal_error'], $this->body($response));
    }

    public function testItLeavesExceptionsOutsideTheApiAlone(): void
    {
        $event = $this->event(new HabitNotFoundException(), '/admin/habits');

        (new ApiExceptionListener(false))($event);

        self::assertNull($event->getResponse());
    }

    private function handle(\Throwable $throwable): Response
    {
        $event = $this->event($throwable, '/api/habits/unknown/entries/2026-09-08');

        (new ApiExceptionListener(false))($event);

        $response = $event->getResponse();
        self::assertNotNull($response, 'the listener must answer for every exception under /api');

        return $response;
    }

    private function event(\Throwable $throwable, string $path): ExceptionEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $content = $response->getContent();
        self::assertNotFalse($content);

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Stands in for the real translator: it knows the German texts of the
     * keys used here and fills the `{{ … }}` placeholders like the validator
     * domain does, and it insists on the `validators` domain.
     */
    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            private const array MESSAGES = [
                'habit_entry.value.out_of_scale' => 'Der Wert muss eine ganze Zahl zwischen {{ min }} und {{ max }} sein.',
                'habit_entry.value.exactly_one' => 'Gib genau einen Wert an.',
                'habit_entry.date.future' => 'Du kannst keinen Wert für die Zukunft eintragen.',
                'habit_entry.date.invalid' => 'Das Datum muss ein gültiges Datum im Format JJJJ-MM-TT sein.',
                'habit_entry.value.numeric_expected' => 'Hier wird eine Zahl erwartet.',
            ];

            /**
             * @param array<mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                TestCase::assertSame('validators', $domain, 'violation messages live in the validators domain');

                return strtr(self::MESSAGES[$id] ?? $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };
    }
}
