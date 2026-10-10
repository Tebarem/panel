# OAuth2 / OpenID Connect Provider

The panel can act as an OAuth2 and OpenID Connect (OIDC) identity provider, so other applications can offer
"Sign in with Pterodactyl". It is built on [Laravel Passport](https://laravel.com/docs/passport) 13
(`laravel/passport ~13.7.6`; newer 13.x releases need phpseclib 4, which the panel does not use yet).

Supported:

- Authorization Code grant with PKCE, `S256` only (`plain` is rejected), and refresh tokens.
- OpenID Connect ID tokens (RS256) with `nonce` and `auth_time`, issued on the authorization code exchange.
- Scopes `openid`, `profile`, `email`.
- Confidential clients (client secret) and public clients (PKCE only).
- Per-application "skip consent" for applications you operate.

Not supported: the implicit grant, the password grant, the device code grant, personal access tokens, and
client management through the Passport API. Clients are only managed by administrators in the panel. Passport
keeps the `client_credentials` grant enabled server-wide, but every panel client's `grant_types` is
`authorization_code` and `refresh_token` only, so that grant is refused for them.

Access tokens only work on `/oauth/userinfo`. They are not accepted by `/api/client` or `/api/application`.

## Setup

1. Install dependencies. `composer.lock` is not updated by the change that introduced this feature, so run the
   update first (on a machine with PHP and Composer):

   ```bash
   composer update laravel/passport
   composer install --no-dev --optimize-autoloader
   ```

2. Run the migrations. The Passport table migrations are included in `database/migrations`
   (`2026_10_10_190000` to `2026_10_10_190300`), together with the `skip_authorization` column migration.

   ```bash
   php artisan migrate --force
   ```

   **Do not** run `php artisan passport:install` or `php artisan vendor:publish --tag=passport-migrations`.
   Both would add the Passport migrations a second time and fail on existing tables. The device code migration
   from Passport is intentionally not included.

3. Generate the signing keys:

   ```bash
   php artisan passport:keys
   ```

   This writes `storage/oauth-private.key` and `storage/oauth-public.key`. Run it as the same user that runs the
   web server (for example `www-data`), and make the private key readable only by that user:

   ```bash
   chmod 600 storage/oauth-private.key
   ```

   The key files are git-ignored. Back them up with the rest of `storage/`. Replacing them invalidates every
   issued token and ID token.

   Alternatively, provide the keys through the environment (for example in Docker or a secrets manager):

   ```env
   PASSPORT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
   PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
   ```

4. Optional: set `PTERODACTYL_OAUTH_EMAIL_VERIFIED=true` only if you know every email address on the panel has
   been verified. The default is `false`, which is reported as `email_verified: false`.

5. Make sure `APP_URL` is the public URL of the panel (for example `https://panel.example.com`). It is the OIDC
   issuer (`iss`), it appears in the discovery document, and it decides which login redirects are honoured.
   Clients must be configured with exactly this issuer.

6. The scheduler runs `php artisan passport:purge` daily, which removes expired and revoked tokens and
   authorization codes. Make sure the panel's scheduler (cron) is running.

## Lifetimes

| Item | Lifetime |
| --- | --- |
| Access token | 1 hour |
| Refresh token | 30 days |
| Authorization code | 10 minutes (fixed by Passport) |
| ID token | `pterodactyl.oauth.id_token_ttl`, default 1 hour |

## Creating an application

In the admin area, open **OAuth Apps** and choose **Create New**.

- **Name**: shown to users on the consent screen.
- **Redirect URIs**: one per line. Requests must match one of them exactly. Use `https://` URLs. `http://` is only
  accepted for `localhost`, `127.0.0.1` and `[::1]`. Custom schemes (for example `com.example.app:/callback`) are
  accepted for native applications. Fragments (`#...`) are not allowed, and `javascript:`, `data:`, `file:` and
  `vbscript:` are rejected.
- **Confidential client**: for server-side applications. A client secret is generated and shown once. Copy it
  immediately.
- **Skip consent screen**: only for applications you operate. Users are not asked to approve access.

**Regenerate Secret** replaces the secret and revokes every token issued to the application. The old secret stops
working immediately. **Revoke All Tokens** revokes all access tokens, refresh tokens and authorization codes.
Deleting an application does the same and then removes it.

## Endpoints

| Endpoint | Purpose |
| --- | --- |
| `GET /.well-known/openid-configuration` | OIDC discovery document |
| `GET /oauth/authorize` | Authorization endpoint (asks the signed-in user for consent) |
| `POST /oauth/token` | Token endpoint (`authorization_code` and `refresh_token` grants) |
| `GET /oauth/jwks` | Public signing key as a JWK Set (`kid` is the RFC 7638 thumbprint) |
| `GET` or `POST /oauth/userinfo` | Claims for the access token's user (requires the `openid` scope) |

Users who are not signed in are sent to the panel login, including the two-factor step if enabled. After signing
in they return to the original authorization request. Requests that use `prompt=none` get an OAuth error instead.

### Claims

| Scope | Claims |
| --- | --- |
| always | `sub` (the user's UUID) |
| `profile` | `preferred_username` (username), `name`, `given_name`, `family_name`, `groups` (`["admin"]` for root admins, otherwise `[]`) |
| `email` | `email`, `email_verified` (see `PTERODACTYL_OAUTH_EMAIL_VERIFIED`) |

ID tokens also contain `iss`, `aud` (client ID), `azp`, `iat`, `exp`, and `auth_time`. They include `nonce` when the
authorization request sent one. The nonce and the time of login are carried from the authorization request to the
code exchange with the authorization code. An ID token is only issued on the authorization code exchange, not on
refresh token requests.

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

Any other OIDC client works the same way. Where the client supports discovery, point it at
`https://panel.example.com/.well-known/openid-configuration`.

## Testing by hand

```bash
curl -s https://panel.example.com/.well-known/openid-configuration | jq .
curl -s https://panel.example.com/oauth/jwks | jq .
```

## Security notes

- Redirect URIs are matched exactly. Only register the callback URLs your applications actually use.
- Do not enable **Skip consent screen** for applications you do not control.
- Two-factor authentication is enforced on the authorization endpoint when the panel requires it.
- Access and refresh tokens are signed and stored by Passport. ID tokens are signed with the same key pair.
- If you suspect an application's secret or tokens have leaked, regenerate the secret or revoke its tokens.
