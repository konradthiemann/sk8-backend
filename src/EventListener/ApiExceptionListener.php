<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ProvidesApiErrorCode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Converts every exception under /api into the JSON error contract:
 *
 *   {"error": "<snake_case_code>"}
 *   {"error": "validation_failed", "violations": [{"field": "...", "message": "..."}]}
 *
 * Runs after Symfony's security listener (priority 1), which has already turned
 * missing credentials into an HTTP 401, and before the default HTML error renderer (-128).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -32)]
final readonly class ApiExceptionListener
{
    private const string API_PREFIX = '/api';

    /**
     * Status codes whose error code differs from the snake_cased HTTP reason phrase.
     */
    private const array ERROR_CODES = [
        Response::HTTP_UNAUTHORIZED => 'unauthorized',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'validation_failed',
    ];

    public function __construct(
        #[Autowire(param: 'kernel.debug')]
        private bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), self::API_PREFIX)) {
            return;
        }

        $throwable = $event->getThrowable();
        $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
        $headers = [];

        if ($throwable instanceof HttpExceptionInterface) {
            $statusCode = $throwable->getStatusCode();
            $headers = $throwable->getHeaders();
        }

        $body = ['error' => $throwable instanceof ProvidesApiErrorCode
            ? $throwable->getApiErrorCode()
            : $this->errorCode($statusCode)];

        $validationFailure = $throwable->getPrevious();
        if (Response::HTTP_UNPROCESSABLE_ENTITY === $statusCode && $validationFailure instanceof ValidationFailedException) {
            $body['violations'] = $this->violations($validationFailure);
        }

        if ($this->debug && $statusCode >= 500) {
            // Never leaks in production: only the dev/test kernels run with debug enabled.
            $body['message'] = $throwable->getMessage();
        }

        $event->setResponse(new JsonResponse($body, $statusCode, $headers));
    }

    private function errorCode(int $statusCode): string
    {
        if (isset(self::ERROR_CODES[$statusCode])) {
            return self::ERROR_CODES[$statusCode];
        }

        if ($statusCode >= 500) {
            return 'internal_error';
        }

        $reason = Response::$statusTexts[$statusCode] ?? 'error';

        return strtolower(str_replace([' ', '-', "'"], ['_', '_', ''], $reason));
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function violations(ValidationFailedException $exception): array
    {
        $violations = [];
        foreach ($exception->getViolations() as $violation) {
            $violations[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $violations;
    }
}
