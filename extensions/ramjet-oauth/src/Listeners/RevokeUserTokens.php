<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Listeners;

use Pterodactyl\Events\User\Deleting;
use Pterodactyl\Events\User\PasswordChanged;
use Ramjet\OAuth\Services\OAuthClientTokens;

/**
 * Revokes every OAuth access token, refresh token and authorization code of a user when the user changes their
 * password or is deleted. Applications that hold those tokens lose access, so they must sign the user in again.
 */
class RevokeUserTokens
{
    public function __construct(private readonly OAuthClientTokens $tokens) {}

    public function handle(PasswordChanged|Deleting $event): void
    {
        $this->tokens->revokeForUser($event->user);
    }
}
