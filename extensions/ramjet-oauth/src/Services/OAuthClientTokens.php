<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Pterodactyl\Models\User;
use Ramjet\OAuth\Models\OAuthClient;

/**
 * Revokes OAuth tokens and authorization codes: everything issued to a client, or everything issued to a user.
 */
class OAuthClientTokens
{
    /**
     * Revokes every access token, refresh token and authorization code issued to an application.
     */
    public function revokeAll(OAuthClient $client): void
    {
        $this->revoke(
            fn (Builder $query): Builder => $query->where('client_id', $client->getKey()),
        );
    }

    /**
     * Revokes every access token, refresh token and authorization code issued to a user, for every application.
     * Called when the user changes their password or is deleted.
     */
    public function revokeForUser(User $user): void
    {
        $this->revoke(
            fn (Builder $query): Builder => $query->where('user_id', $user->getKey()),
        );
    }

    /**
     * Revokes everything and removes the client, atomically.
     */
    public function delete(OAuthClient $client): void
    {
        DB::transaction(function () use ($client): void {
            $this->revokeAll($client);
            $client->delete();
        });
    }

    /**
     * @param  callable(Builder): Builder  $scope  narrows a token query to the tokens being revoked
     */
    private function revoke(callable $scope): void
    {
        $tokenModel = Passport::tokenModel();
        $refreshTokenModel = Passport::refreshTokenModel();
        $authCodeModel = Passport::authCodeModel();

        // Subqueries rather than id lists: a user or client with many tokens would otherwise exceed the placeholder limit.
        $tokenIds = $scope($tokenModel::query())->select('id');

        $refreshTokenModel::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        $scope($tokenModel::query())->update(['revoked' => true]);
        $scope($authCodeModel::query())->update(['revoked' => true]);
    }
}
