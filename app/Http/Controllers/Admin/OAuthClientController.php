<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\View\View;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\OAuthClient;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\OAuthClientFormRequest;

class OAuthClientController extends Controller
{
    /**
     * Session flash key used to show a client secret exactly once, straight after it is generated.
     */
    public const SECRET_FLASH_KEY = 'oauth_client_secret';

    public function __construct(protected AlertsMessageBag $alert)
    {
    }

    /**
     * List all OAuth applications.
     */
    public function index(): View
    {
        return view('admin.oauth.index', [
            'clients' => OAuthClient::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Form to register a new OAuth application.
     */
    public function create(): View
    {
        return view('admin.oauth.create');
    }

    /**
     * Register a new OAuth application. Confidential clients are given a secret, which is shown once.
     */
    public function store(OAuthClientFormRequest $request): RedirectResponse
    {
        $secret = $request->boolean('confidential') ? Str::random(40) : null;

        $client = new OAuthClient();
        $client->forceFill([
            'name' => $request->input('name'),
            // Passport's secret mutator stores a hash; the plain value is only shown below.
            'secret' => $secret,
            'provider' => null,
            'redirect_uris' => $request->redirectUris(),
            'grant_types' => OAuthClient::GRANT_TYPES,
            'revoked' => false,
            'skip_authorization' => $request->boolean('skip_authorization'),
        ])->save();

        $this->alert->success('OAuth application was created.')->flash();

        $redirect = redirect()->route('admin.oauth.view', $client);
        if (!is_null($secret)) {
            $redirect = $redirect->with(self::SECRET_FLASH_KEY, $secret);
        }

        return $redirect;
    }

    /**
     * View and edit an OAuth application.
     */
    public function view(OAuthClient $client): View
    {
        return view('admin.oauth.view', [
            'client' => $client,
        ]);
    }

    /**
     * Update the name, redirect URIs and consent setting of an application.
     */
    public function update(OAuthClientFormRequest $request, OAuthClient $client): RedirectResponse
    {
        $client->forceFill([
            'name' => $request->input('name'),
            'redirect_uris' => $request->redirectUris(),
            'skip_authorization' => $request->boolean('skip_authorization'),
        ])->save();

        $this->alert->success('OAuth application was updated.')->flash();

        return redirect()->route('admin.oauth.view', $client);
    }

    /**
     * Replace the client secret of a confidential application and revoke every token issued to it, since
     * those were issued by the previous state. The new secret is shown once.
     *
     * @throws \Throwable
     */
    public function regenerateSecret(OAuthClient $client): RedirectResponse
    {
        if (!$client->isConfidential()) {
            $this->alert->danger('Public applications do not have a client secret.')->flash();

            return redirect()->route('admin.oauth.view', $client);
        }

        $secret = Str::random(40);

        DB::transaction(function () use ($client, $secret) {
            $client->forceFill(['secret' => $secret])->save();
            $this->revokeAllTokens($client);
        });

        $this->alert->warning('The client secret was regenerated and all tokens issued to this application were revoked. Update your application with the new secret.')->flash();

        return redirect()->route('admin.oauth.view', $client)->with(self::SECRET_FLASH_KEY, $secret);
    }

    /**
     * Revoke every access token, refresh token and authorization code issued to an application.
     */
    public function revokeTokens(OAuthClient $client): RedirectResponse
    {
        $this->revokeAllTokens($client);

        $this->alert->success('All tokens issued to this application were revoked.')->flash();

        return redirect()->route('admin.oauth.view', $client);
    }

    /**
     * Delete an application, revoking everything issued to it first.
     *
     * @throws \Throwable
     */
    public function delete(OAuthClient $client): RedirectResponse
    {
        DB::transaction(function () use ($client) {
            $this->revokeAllTokens($client);
            $client->delete();
        });

        $this->alert->success('OAuth application was deleted.')->flash();

        return redirect()->route('admin.oauth');
    }

    private function revokeAllTokens(OAuthClient $client): void
    {
        $tokenModel = Passport::tokenModel();
        $refreshTokenModel = Passport::refreshTokenModel();
        $authCodeModel = Passport::authCodeModel();

        $tokenIds = $tokenModel::query()->where('client_id', $client->id)->pluck('id');

        $refreshTokenModel::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        $tokenModel::query()->where('client_id', $client->id)->update(['revoked' => true]);
        $authCodeModel::query()->where('client_id', $client->id)->update(['revoked' => true]);
    }
}
