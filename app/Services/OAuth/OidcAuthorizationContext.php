<?php

namespace Pterodactyl\Services\OAuth;

/**
 * Per-request state linking an OpenID Connect authorization request to the authorization code it
 * produces and, later, to the token exchange that consumes that code.
 */
class OidcAuthorizationContext
{
    /**
     * Context for the authorization request being processed: ['nonce' => ?string, 'auth_time' => ?int].
     */
    public ?array $pending = null;

    /**
     * Context of the authorization code being exchanged for tokens in this request.
     */
    public ?array $exchanged = null;
}
