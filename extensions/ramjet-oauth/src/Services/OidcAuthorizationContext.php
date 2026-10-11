<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Services;

/**
 * Per-request state linking an OpenID Connect authorization request to the authorization code it
 * produces and, later, to the token exchange that consumes that code.
 */
class OidcAuthorizationContext
{
    /**
     * Context of the authorization request being processed: ['nonce' => ?string, 'auth_time' => ?int].
     *
     * @var array<string, mixed>|null
     */
    public ?array $pending = null;

    /**
     * Context of the authorization code being exchanged for tokens in this request.
     *
     * @var array<string, mixed>|null
     */
    public ?array $exchanged = null;
}
