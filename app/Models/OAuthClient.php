<?php

namespace Pterodactyl\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;

/**
 * OAuth client registered with the panel's OpenID Connect provider.
 *
 * @property string $id UUID
 * @property string $name
 * @property string|null $secret Hashed by Passport's secret mutator; the plain value is only available right after it is set
 * @property string[] $redirect_uris
 * @property string[] $grant_types
 * @property bool $skip_authorization
 * @property bool $revoked
 */
class OAuthClient extends PassportClient
{
    /**
     * Grant types every client in this panel is allowed to use. client_credentials is enabled
     * server-wide by Passport, so it is kept out of this list and therefore rejected per client.
     */
    public const GRANT_TYPES = ['authorization_code', 'refresh_token'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Merged rather than redefining $casts so Passport's own casts are kept.
        $this->mergeCasts(['skip_authorization' => 'boolean']);
    }

    /**
     * First-party clients (configured with skip_authorization) are not shown the consent screen.
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return (bool) $this->skip_authorization;
    }

    /**
     * Confidential clients have a client secret, public clients (browser-only and native apps) do not.
     */
    public function isConfidential(): bool
    {
        return $this->confidential();
    }

    /**
     * @return string[]
     */
    public function redirectUriList(): array
    {
        return array_values($this->redirect_uris ?? []);
    }
}
