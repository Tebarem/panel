<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Ramjet\OAuth\Http\Requests\StoreOAuthClientRequest;
use Ramjet\OAuth\Http\Requests\UpdateOAuthClientRequest;
use Ramjet\OAuth\Models\OAuthClient;
use Ramjet\OAuth\Services\OAuthClientTokens;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Admin API for OAuth applications, mounted at /api/admin/extensions/ramjet-oauth (routes/admin.php).
 *
 * The client secret is returned only by the create and regenerate responses, and is stored hashed.
 */
class OAuthClientController extends Controller
{
    private const SECRET_LENGTH = 40;

    public function __construct(private readonly OAuthClientTokens $tokens) {}

    public function index(): JsonResponse
    {
        $clients = OAuthClient::query()->orderBy('name')->get();

        return new JsonResponse([
            'data' => $clients->map(fn (OAuthClient $client): array => $client->toApiArray())->values()->all(),
        ]);
    }

    public function store(StoreOAuthClientRequest $request): JsonResponse
    {
        $secret = $request->isConfidential() ? Str::random(self::SECRET_LENGTH) : null;

        $client = new OAuthClient();
        $client->forceFill([
            'name' => $request->input('name'),
            // Passport hashes the secret when it is stored; the plain value is only returned below.
            'secret' => $secret,
            'provider' => null,
            'redirect_uris' => $request->redirectUris(),
            'grant_types' => OAuthClient::GRANT_TYPES,
            'revoked' => false,
            'skip_authorization' => $request->boolean('skip_authorization'),
        ])->save();

        return new JsonResponse(['data' => $this->withSecret($client, $secret)], Response::HTTP_CREATED);
    }

    public function update(UpdateOAuthClientRequest $request, OAuthClient $client): JsonResponse
    {
        $client->forceFill([
            'name' => $request->input('name'),
            'redirect_uris' => $request->redirectUris(),
            'skip_authorization' => $request->boolean('skip_authorization'),
        ])->save();

        return new JsonResponse(['data' => $client->refresh()->toApiArray()]);
    }

    /**
     * Replaces the secret of a confidential client and revokes every token issued to it, since those were issued
     * under the previous secret. The new secret is returned once.
     *
     * @throws Throwable
     */
    public function regenerateSecret(OAuthClient $client): JsonResponse
    {
        if (! $client->isConfidential()) {
            throw new DisplayException('Public applications do not have a client secret.');
        }

        $secret = Str::random(self::SECRET_LENGTH);

        DB::transaction(function () use ($client, $secret): void {
            $client->forceFill(['secret' => $secret])->save();
            $this->tokens->revokeAll($client);
        });

        return new JsonResponse(['data' => $this->withSecret($client->refresh(), $secret)]);
    }

    public function revoke(OAuthClient $client): Response
    {
        $this->tokens->revokeAll($client);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws Throwable
     */
    public function destroy(OAuthClient $client): Response
    {
        $this->tokens->delete($client);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function withSecret(OAuthClient $client, ?string $secret): array
    {
        return array_merge($client->toApiArray(), ['secret' => $secret]);
    }
}
