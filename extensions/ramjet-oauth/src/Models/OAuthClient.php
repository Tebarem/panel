<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;

/**
 * OAuth client registered with the panel's OpenID Connect provider.
 *
 * @property string $id UUID
 * @property string $name
 * @property string|null $secret Hashed by Passport; the plain value is only available right after it is set
 * @property string[] $redirect_uris
 * @property string[] $grant_types
 * @property bool $skip_authorization
 * @property bool $revoked
 */
class OAuthClient extends PassportClient
{
    /**
     * Grant types every client of this provider may use. client_credentials is enabled server-wide by
     * Passport, so it is left out here and therefore refused for every client.
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
     *
     * @param  string[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return (bool) $this->skip_authorization;
    }

    /**
     * Confidential clients have a client secret, public clients (browser and native applications) do not.
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

    /**
     * The representation used by the admin API. The secret is never part of it; the create and
     * regenerate responses add it explicitly.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'redirect_uris' => $this->redirectUriList(),
            'confidential' => $this->isConfidential(),
            'skip_authorization' => (bool) $this->skip_authorization,
            'revoked' => (bool) $this->revoked,
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
