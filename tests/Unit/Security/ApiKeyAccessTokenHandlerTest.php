<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ApiKeyAccessTokenHandler;
use App\Security\ApiUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class ApiKeyAccessTokenHandlerTest extends TestCase
{
    public function testItReturnsABadgeForTheConfiguredKey(): void
    {
        $handler = new ApiKeyAccessTokenHandler('s3cret');

        $badge = $handler->getUserBadgeFrom('s3cret');

        self::assertSame(ApiUser::IDENTIFIER, $badge->getUserIdentifier());

        $loader = $badge->getUserLoader();
        self::assertNotNull($loader);

        $user = $loader(ApiUser::IDENTIFIER);
        self::assertInstanceOf(ApiUser::class, $user);
        self::assertSame(['ROLE_API'], $user->getRoles());
    }

    public function testItRejectsAWrongKey(): void
    {
        $handler = new ApiKeyAccessTokenHandler('s3cret');

        $this->expectException(BadCredentialsException::class);

        $handler->getUserBadgeFrom('nope');
    }

    public function testItRejectsEveryKeyWhenNoneIsConfigured(): void
    {
        $handler = new ApiKeyAccessTokenHandler('');

        $this->expectException(BadCredentialsException::class);

        $handler->getUserBadgeFrom('');
    }
}
