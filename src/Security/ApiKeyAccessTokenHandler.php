<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Validates the static API key carried in the X-Api-Key header (ADR-006).
 *
 * Plugged into Symfony's access_token authenticator (config/packages/security.yaml).
 */
final readonly class ApiKeyAccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        #[Autowire(env: 'APP_API_KEY')]
        #[\SensitiveParameter]
        private string $apiKey,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // An empty configured key would otherwise accept an empty header value.
        if ('' === $this->apiKey || !hash_equals($this->apiKey, $accessToken)) {
            throw new BadCredentialsException('Invalid API key.');
        }

        return new UserBadge(ApiUser::IDENTIFIER, static fn (): ApiUser => new ApiUser());
    }
}
