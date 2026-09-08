<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Technical user representing "whoever holds the API key" (ADR-006).
 *
 * There is no user table: a valid X-Api-Key header authenticates as this single identity.
 */
final class ApiUser implements UserInterface
{
    public const string IDENTIFIER = 'api';
    public const string ROLE = 'ROLE_API';

    public function getUserIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // Nothing to erase: the key is never stored on the user object.
    }
}
