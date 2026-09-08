<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Reads the raw API key from the X-Api-Key header.
 *
 * Symfony's built-in header extractor expects "Authorization: Bearer <token>";
 * this one is deliberately minimal and has no token-format restrictions.
 */
final class ApiKeyHeaderExtractor implements AccessTokenExtractorInterface
{
    public const string HEADER = 'X-Api-Key';

    public function extractAccessToken(Request $request): ?string
    {
        $key = $request->headers->get(self::HEADER);

        if (null === $key || '' === trim($key)) {
            return null;
        }

        return $key;
    }
}
