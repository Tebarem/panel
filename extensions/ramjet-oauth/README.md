# Ramjet OAuth

An OAuth 2.0 and OpenID Connect provider built into the panel. Other applications can offer "Sign in with
Pterodactyl" against it. Built on Laravel Passport, with the hardening of the 1.x implementation.

## What is supported

- Authorization code grant with PKCE. Only `S256` is accepted; `plain` is rejected.
- Refresh tokens. Access tokens last 1 hour, refresh tokens 30 days, authorization codes 10 minutes.
- OpenID Connect ID tokens (RS256) with `nonce` and `auth_time`, issued on the authorization code exchange only.
- Scopes `openid`, `profile` and `email`.
- Confidential clients (client secret) and public clients (PKCE only).
- Per-application "skip consent" for applications you operate.

Not supported: the implicit, password and device code grants, personal access tokens, and client management
through Passport. Applications are managed by root administrators in the admin area (**OAuth Apps**).

Access tokens only work on `/oauth/userinfo`. They are not accepted by `/api/client` or `/api/application`.

## Setup

1. Enable the extension in the admin area. Enabling runs its migrations (the Passport tables and the
   `skip_authorization` column). Passport's device code table is intentionally not created.
2. Create the signing keys, unless you provide them through the environment:

   ```bash
   php artisan ramjet-oauth:keys
   ```

   This writes `storage/oauth-private.key` and `storage/oauth-public.key` if they are missing. Run it as the user
   that runs the panel. The command is safe to repeat. `--force` replaces the keys, which invalidates every issued
   token and ID token.

   Alternatively set the keys in the environment. Newlines may be written as `\n`:

   ```env
   PASSPORT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
   PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
   ```

   The key files are not in git. Back them up with the rest of `storage/`.

3. Make sure `APP_URL` is the public URL of the panel (for example `https://panel.example.com`). It is the issuer
   (`iss`) and appears in the discovery document. Clients must use exactly this issuer.

4. Optional: the extension setting **Report email addresses as verified** (off by default) makes applications receive
   `email_verified: true` for every user. Only enable it if every email address on the panel has been verified. It is
   read per request, so it works with cached configuration.

5. The scheduler runs `php artisan passport:purge` daily, which removes expired and revoked tokens and authorization
   codes. The panel's scheduler (cron) must be running.

## Applications

In the admin area, open **OAuth Apps** and choose **Create**.

- **Name**: shown on the consent screen.
- **Redirect URIs**: one per line; requests must match one exactly. Allowed: `https://` URLs, `http://` only for
  `localhost`, `127.0.0.1` and `[::1]`, and custom schemes for native apps (`com.example.app:/callback`). Fragments
  (`#...`) are not allowed, and `javascript:`, `data:`, `file:` and `vbscript:` are rejected.
- **Confidential**: generates a client secret, shown once. Copy it immediately.
- **Skip consent screen**: only for applications you operate.

Actions on an application:

- **Regenerate secret** replaces the secret and revokes every token issued to the application.
- **Revoke tokens** revokes all access tokens, refresh tokens and unused authorization codes. The application stays
  active and can sign users in again.
- **Delete** revokes everything issued to the application and removes it.

A user who changes their password, or whose account is deleted, loses every grant they gave to any application: their
access tokens, refresh tokens and authorization codes are revoked at once.

## Endpoints

| Endpoint | Purpose |
| --- | --- |
| `GET /.well-known/openid-configuration` | OpenID Connect discovery document |
| `GET /oauth/authorize` | Authorization endpoint. Asks the signed-in user for consent |
| `POST /oauth/token` | Token endpoint (`authorization_code` and `refresh_token` grants) |
| `GET /oauth/jwks` | Public signing key as a JWK Set. `kid` is the RFC 7638 thumbprint |
| `GET` or `POST /oauth/userinfo` | Claims for the access token's user. Requires the `openid` scope |

Signed-out users are sent to the panel login, including the two-factor step when it is required. They return to the
original authorization request afterwards. A request with `prompt=none` gets `login_required` on the redirect URI
instead. `prompt=login` asks for the password again.

### Claims

| Scope | Claims |
| --- | --- |
| always | `sub` (the user's UUID) |
| `profile` | `preferred_username` (username), `name`, `given_name`, `family_name`, `groups` (`["admin"]` for root administrators, otherwise `[]`) |
| `email` | `email`, `email_verified` (see `PTERODACTYL_OAUTH_EMAIL_VERIFIED`) |

ID tokens also contain `iss`, `aud` (client ID), `azp`, `iat`, `exp` and `auth_time`, plus `nonce` when the
authorization request sent one. Refresh token requests do not return an ID token.

### Admin API

Root administrators, under `/api/admin/extensions/ramjet-oauth`:

| Method and path | Result |
| --- | --- |
| `GET /clients` | `{data: [client]}` |
| `POST /clients` | `201` with `{data: {…client, secret}}`. The secret is `null` for public clients |
| `PATCH /clients/{id}` | `{data: client}`. Name, redirect URIs and consent setting |
| `POST /clients/{id}/secret` | `{data: {…client, secret}}`. Also revokes the client's tokens |
| `POST /clients/{id}/revoke` | `204`. Revokes tokens only |
| `DELETE /clients/{id}` | `204` |

A client is `{id, name, redirect_uris, confidential, skip_authorization, revoked, created_at}`. Its secret is never
returned except by the create and secret responses. Validation errors use the panel's standard error format
(`errors[]` with `detail` and `meta.source_field`).

## Example: Grafana (generic OAuth)

Create an application with the redirect URI `https://grafana.example.com/login/generic_oauth` and mark it as
confidential. Then set the following in `grafana.ini` (or the matching `GF_AUTH_GENERIC_OAUTH_*` variables):

```ini
[auth.generic_oauth]
enabled = true
name = Pterodactyl
allow_sign_up = true
client_id = <client id from the panel>
client_secret = <client secret from the panel>
scopes = openid profile email
auth_url = https://panel.example.com/oauth/authorize
token_url = https://panel.example.com/oauth/token
api_url = https://panel.example.com/oauth/userinfo
use_pkce = true
login_attribute_path = preferred_username
name_attribute_path = name
email_attribute_path = email
role_attribute_path = contains(groups[*], 'admin') && 'Admin' || 'Viewer'
```

Any other OpenID Connect client works the same way. Where the client supports discovery, point it at
`https://panel.example.com/.well-known/openid-configuration`.

## Checking by hand

```bash
curl -s https://panel.example.com/.well-known/openid-configuration | jq .
curl -s https://panel.example.com/oauth/jwks | jq .
```

## Security notes

- Redirect URIs are matched exactly. Register only the callback URLs your applications use.
- Do not enable **Skip consent screen** for applications you do not control.
- Two-factor authentication applies on the authorization endpoint when the panel requires it.
- If an application's secret or tokens may have leaked, regenerate the secret or revoke its tokens.
- Secrets are stored hashed. The access and refresh tokens are stored by Passport.

## Development

The tests run in the panel's test environment against the `testing_oauth2` database, from the panel root:

```bash
vendor/bin/pest --bootstrap extensions/ramjet-oauth/tests/bootstrap.php extensions/ramjet-oauth/tests
```

The bootstrap runs the panel's test bootstrap (fresh database), applies this extension's migrations, and autoloads the
test namespace. Tests enable the extension through the provider loader, the same way the panel does at runtime.
