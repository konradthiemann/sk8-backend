<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Habit\HabitEntryRequest;
use App\Dto\Habit\HabitEntryResponse;
use App\Service\Habit\HabitEntryService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Records, corrects and deletes the value of one habit on one day (T-0402).
 * The URL is the identity of the entry (habit and day), so PUT is an
 * idempotent upsert: 201 when it creates, 200 when it corrects. There is
 * deliberately no GET on this URL (the day view reads entries) and hence no
 * Location header.
 */
#[OA\Tag(name: 'Habit')]
final class HabitEntryController
{
    private const string ENDPOINT = '/api/habits/{habitId}/entries/{date}';
    private const string DATE_PATTERN = '\d{4}-\d{2}-\d{2}';
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';
    private const string VALIDATION_ERROR_RESPONSE = '#/components/schemas/ValidationErrorResponse';

    public function __construct(
        private readonly HabitEntryService $service,
    ) {
    }

    #[Route(self::ENDPOINT, name: 'api_habit_entries_put', requirements: ['habitId' => Requirement::UUID, 'date' => self::DATE_PATTERN], methods: ['PUT'])]
    #[OA\Put(
        summary: 'Record or correct the value of a habit on a day',
        description: 'Creates the entry of this habit and day (201) or replaces value and note of the existing one (200; id and createdAt stay). Repeating the same call is safe. 0 and false are values. The day must be a calendar day from 2026-01-01 up to today; only active habits accept values. Exactly one of valueNumeric and valueBool must be set.',
    )]
    #[OA\Parameter(name: 'habitId', in: 'path', required: true, description: 'ID of the habit', schema: new OA\Schema(type: 'string', format: 'uuid'), example: '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d')]
    #[OA\Parameter(name: 'date', in: 'path', required: true, description: 'The calendar day, YYYY-MM-DD', schema: new OA\Schema(type: 'string', format: 'date'), example: '2026-09-08')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            ref: new Model(type: HabitEntryRequest::class),
            example: ['valueNumeric' => 7.5, 'note' => 'spät ins Bett, früh raus'],
        ),
    )]
    #[OA\Response(
        response: 201,
        description: 'The day had no entry yet, it was created',
        content: new OA\JsonContent(ref: new Model(type: HabitEntryResponse::class), example: [
            'id' => '0199a112-8b3d-7c4e-a1f2-3d4e5f6a7b8c',
            'habitId' => '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d',
            'entryDate' => '2026-09-08',
            'valueNumeric' => 7.5,
            'valueBool' => null,
            'note' => 'spät ins Bett, früh raus',
            'createdAt' => '2026-09-08T19:04:11+00:00',
        ]),
    )]
    #[OA\Response(
        response: 200,
        description: 'The existing entry of that day was corrected',
        content: new OA\JsonContent(ref: new Model(type: HabitEntryResponse::class), example: [
            'id' => '0199a112-8b3d-7c4e-a1f2-3d4e5f6a7b8c',
            'habitId' => '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d',
            'entryDate' => '2026-09-08',
            'valueNumeric' => 8,
            'valueBool' => null,
            'note' => null,
            'createdAt' => '2026-09-08T19:04:11+00:00',
        ]),
    )]
    #[OA\Response(response: 400, description: 'Empty body or malformed JSON', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'bad_request']))]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 404, description: 'Unknown or inactive habit, or a path that is not a UUID / YYYY-MM-DD', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'habit_not_found']))]
    #[OA\Response(response: 415, description: 'Content-Type is not application/json', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unsupported_media_type']))]
    #[OA\Response(
        response: 422,
        description: 'Date or value rejected: no calendar date, future, before 2026-01-01, wrong value type, out of range or scale, off the step, both or no value, note longer than 500 characters',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [['field' => 'valueNumeric', 'message' => 'Der Wert muss eine ganze Zahl zwischen 0 und 10 sein.']],
        ]),
    )]
    public function put(string $habitId, string $date, #[MapRequestPayload] HabitEntryRequest $request): JsonResponse
    {
        $result = $this->service->put($habitId, $date, $request);

        return new JsonResponse(
            HabitEntryResponse::fromEntity($result->entry),
            $result->created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[Route(self::ENDPOINT, name: 'api_habit_entries_delete', requirements: ['habitId' => Requirement::UUID, 'date' => self::DATE_PATTERN], methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Delete the value of a habit on a day',
        description: 'Brings the day back to "not recorded". Also allowed for a deactivated habit, so an old mistake stays correctable.',
    )]
    #[OA\Parameter(name: 'habitId', in: 'path', required: true, description: 'ID of the habit', schema: new OA\Schema(type: 'string', format: 'uuid'), example: '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d')]
    #[OA\Parameter(name: 'date', in: 'path', required: true, description: 'The calendar day, YYYY-MM-DD', schema: new OA\Schema(type: 'string', format: 'date'), example: '2026-09-08')]
    #[OA\Response(response: 204, description: 'Entry deleted')]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 404, description: 'Unknown habit (habit_not_found) or no entry on that day (habit_entry_not_found)', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'habit_entry_not_found']))]
    #[OA\Response(
        response: 422,
        description: 'The date matches the pattern but is not a calendar date (for example 2026-02-30)',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [['field' => 'date', 'message' => 'Das Datum muss ein gültiges Datum im Format JJJJ-MM-TT sein.']],
        ]),
    )]
    public function delete(string $habitId, string $date): JsonResponse
    {
        $this->service->delete($habitId, $date);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
