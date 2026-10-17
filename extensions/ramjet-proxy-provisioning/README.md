# Ramjet Proxy Provisioning

Lets every customer create exactly one **Ramjet Proxy** server for themselves from the dashboard. The customer
chooses nothing: the egg, node, port, resources and name all come from the admin settings below.

Namespace: `Ramjet\ProxyProvisioning\`. Extension id: `ramjet-proxy-provisioning`.

## What it does

- A card on the dashboard, above the server list (slot `dashboard.before`). It offers "Create proxy" when the
  customer has no proxy, and links to "Your proxy: <name>" once they do.
- One proxy per customer, root administrators included. Admins keep full control through the admin panel; this route
  only limits self-service creation.
- Deleting the proxy server, or changing its owner, frees the slot for the customer who had it.
- **Off by default.** An admin turns it on after choosing the node, the port range and (optionally) the egg.

## Admin settings

Set on the extension's settings page (`registerSettings`). Each has help text on the form.

| Key | Default | Range / rule |
| --- | --- | --- |
| `enabled` | `false` | When off the card is hidden and creation is refused. Existing proxies keep running. |
| `node` | empty | Node id. **Required while enabled**; there is no default node. Integer, at least 1. |
| `port_min` | empty | First allocation port proxies may use on the node. **Required while enabled.** 1024 to 65535. |
| `port_max` | empty | Last allocation port. **Required while enabled.** 1024 to 65535, and at least `port_min`. |
| `egg` | empty | Egg id. Empty means the egg named "Ramjet Proxy". Integer, at least 1. |
| `max_proxies` | `0` | Most proxies that may exist at once across all customers. 0 means no limit. |
| `memory` | `128` | MB, 16 to 65536 |
| `cpu` | `50` | percent of one core, 0 to 1000 (0 is unlimited) |
| `disk` | `512` | MB, 64 to 102400 |
| `swap` | `0` | MB, -1 to 65536 (-1 is unlimited) |
| `io` | `500` | block IO weight, 10 to 1000 |
| `backup_limit` | `1` | 0 to 100 |
| `database_limit` | `0` | 0 to 100 |
| `allocation_limit` | `0` | 0 to 100 |
| `name_pattern` | `{username}-proxy` | up to 120 characters; `{username}` and `{user_id}` are replaced |

"Required while enabled" is checked when a proxy is requested, not when the settings are saved: an incomplete range is
reported by the status endpoint and refused by the create endpoint until it is fixed. A range whose last port is below
its first is treated the same way.

The server takes the egg's single Docker image, the egg's startup command, and each egg variable's default value. It
is created with `start_on_completion` true and `oom_disabled` false.

## Client API

Base: `/api/client/extensions/ramjet-proxy-provisioning`. Requires a signed-in user with a client (account) key. Guests
get 401; application keys get 403; accounts that must enable two-factor authentication get the panel's 400
`TwoFactorAuthRequiredException`. Each request acts only on the caller's own proxy.

### `GET /status`

```json
{
    "enabled": true,
    "has_proxy": true,
    "server": { "identifier": "1a2b3c4d", "name": "alice-proxy", "uuidShort": "1a2b3c4d" },
    "can_create": false,
    "reason": "You already have a Ramjet Proxy."
}
```

`server` is `null` when the customer has no proxy. `reason` is present only when `can_create` is false. The reasons
are, in order: the feature is disabled; the customer already has a proxy; the egg is not configured; no node is
configured; the node is in maintenance; the port range is missing or inverted; the proxy limit is reached; the node has
no room for the proxy's memory and disk; no free allocation in the port range.

### `POST /create`

Request body: `{}` or `{ "confirm": true }`. `confirm` is optional, and when sent it must be `true`. Any other field
is refused with 422, so the customer cannot set the owner, egg, resources, node or port.

Success: `201 Created`

```json
{ "server": { "identifier": "1a2b3c4d", "name": "alice-proxy", "uuidShort": "1a2b3c4d" } }
```

Errors use the panel's standard `errors` envelope:

| Status | When |
| --- | --- |
| 409 | Feature disabled; the customer already has a proxy; another create by this customer is in progress; the egg is missing or has no image; no node is configured; the port range is missing or inverted; the egg has a variable that cannot be set automatically |
| 422 | Proxy limit reached; the node has no room for the proxy; no free allocation in the port range; a field other than `confirm` was sent, or `confirm` is false |
| 503 | The node is in maintenance; another proxy is being placed on the node and the wait ran out; Wings could not be reached or refused the server |
| 429 | More than three create requests in a minute from one customer |

A 503 or a failed create writes a `Log::warning` with the reason, the user id and the node id. A create refused because
the port range is exhausted, or the proxy cap is reached, writes a `Log::info` with the reason and the ids only, so
operators notice a full pool. A proxy that was created but whose ownership row could not be stored is a
`Log::critical` with the user and server ids.

The proxy cap counts every proxy-egg server on the panel, including admin-made ones without a recorded owner row, and
every recorded proxy; the larger of the two counts is compared against `max_proxies`.

Creating a proxy writes the activity event `server:proxy.self-provision`, with the server subject and the
`identifier` property.

### Adoption

If the customer already owns a server on the proxy egg that has no proxy row (an admin made it, or a create stopped
after the server was stored), it becomes their proxy. The create is refused with 409 and the row is recorded, so the
customer keeps exactly one.

## How creation stays race-safe

`ProxyProvisioner::create()` uses two MySQL named locks, not a database transaction:

1. `GET_LOCK('rjproxy:<db>:u:<user id>', 0)`. A second create by the same customer returns 409 at once.
2. The customer's existing proxy is checked (and adopted, if one exists).
3. `GET_LOCK('rjproxy:<db>:n:<node id>', 5)`. Proxy creates on one node are serialised. If the lock is not free in five
   seconds the request gets 503. Under the lock the node is re-read; maintenance, the port range, the proxy limit and
   the node's capacity (`Node::isViable`, which respects overallocation) are checked, and the lowest free allocation in
   the port range is picked.
4. `CreatesServers` (core `CreateServer`) stores the server and calls Wings. If Wings is unreachable or refuses, core
   removes the server again and the request fails with 503. If the chosen allocation was taken concurrently (core
   reports `NoViableAllocationException` or a unique-key violation and rolls its insert back), the create is retried
   once with the next free allocation in the range. A second loss is a 503, "Could not reserve a port, try again."
5. The `ramjet_user_proxies` row is written with `insertOrIgnore`. If it cannot be written after the server exists, the
   customer still gets their server, the failure is logged at critical level, and adoption records the row on their
   next request. The primary key is the user id, so a second row for one user cannot be stored even if the locks were
   bypassed.

`<db>` is the first twelve hex characters of the SHA-1 of the database name, so two panels sharing one database server
do not contend for each other's locks. Both locks are released in a `finally`; a failed release is logged as a warning
and never masks the original error or skips the other release.

**Production topology.** Named locks exist on one MySQL/MariaDB server only. The panel must run against a single
primary: the locks do not hold across a Galera cluster, nor across a read/write split where creates and locks may land
on different servers.

**Why not a transaction.** `CreatesServers::create()` commits the server row, then calls Wings, and Wings immediately
calls back into the panel to read that row on another database connection. A transaction still open at that point
would hide the row, Wings would get a 404, and every create would fail. Named locks are per connection and survive
commits, so they serialise creation without holding a transaction across the Wings call.

The table `ramjet_user_proxies` (`user_id` primary key, `server_id` unique, `created_at`) cascades on delete from both
`users` and `servers`. A row whose server is owned by someone else (the owner was changed) is treated as stale: it is
removed when the customer next looks, and their slot is free.

## Dashboard card

The client bundle (`src/client`) registers one component on `dashboard.before`:

- Hidden entirely while the feature is disabled.
- Loading state, then the create button, which opens a confirmation dialog. The button is disabled when
  `can_create` is false, and the reason is shown under the description.
- While creating: spinner and "Creating your proxy...".
- On success: the status query and the dashboard's server list query (`clientListClientServers`) are invalidated, so
  the new server appears.
- A refused or failed creation shows its message in an alert in the card, and the status query is refreshed.

The stylesheet uses the `rjproxy` Tailwind prefix declared in `extension.json`.

## Layout

```
extension.json                       manifest (provider, autoload, ui entry and prefix)
composer.json                        autoloader suffix RamjetProxyProvisioningExtension
database/migrations/                 ramjet_user_proxies
routes/client.php                    status and create
src/RamjetProxyProvisioningProvider  boot: routes, settings, rate limit
src/ProxySettings.php                typed settings definition and defaults
src/ProxyConfig.php                  settings as read at request time, port range
src/ProxyProvisioner.php             status, named locks, adoption, create
src/ProxyProvisioningException.php   refusals with their HTTP status and cause
src/Http/Controllers/ProxyController.php
src/Http/Requests/CreateProxyRequest.php
src/Models/UserProxy.php
src/client/                          dashboard card, API helpers, slot registration, specs
tests/                               Pest tests (Provisioning/), bootstrap, phpunit.xml, enable helper
```

## Build and test

```bash
# client bundle (dist/ is ignored by git, like the sibling extensions)
cd extensions/ramjet-proxy-provisioning
npx vite build            # or: npm run build
npx tsc --noEmit -p tsconfig.json
npx vitest run --config vitest.config.ts

# PHP (Docker, from the panel root)
docker exec ptero-mysql mysql -uroot -e "CREATE DATABASE IF NOT EXISTS testing_proxyprov"
docker run --rm --network ptero-test -e DB_HOST=ptero-mysql -e DB_USERNAME=root -e DB_DATABASE=testing_proxyprov \
  -e APP_KEY=SomeRandomString3232RandomString -e HASHIDS_SALT=test123 \
  -v "$(pwd -W)":/app -v ptero-v2-vendor:/app/vendor -w /app ptero-php:8.3 \
  php -d memory_limit=-1 vendor/bin/pest --configuration=extensions/ramjet-proxy-provisioning/tests/phpunit.xml

# style
docker run --rm -e APP_KEY=SomeRandomString3232RandomString -v "$(pwd -W)":/app -v ptero-v2-vendor:/app/vendor -w /app \
  ptero-php:8.3 php vendor/bin/pint --test extensions/ramjet-proxy-provisioning
```

Most PHP tests run inside a transaction (`tests/ProxyTestCase.php`), so settings written by one test do not reach the
next. `tests/Provisioning/ProxyWingsCallbackTest.php` is deliberately not transactional: it commits real rows, reads
them from a second connection while Wings is "called", and removes everything it wrote in a `finally`.

The lock tests hold a named lock on that second connection, so the create sees it as held by another request. The
node-busy test therefore takes about five seconds: it waits out the node lock timeout.

## Notes

- The proxy egg is expected to have a single Docker image. If it has several, the first one is used.
- Wings must accept `POST /api/servers` and read the server back through the panel. Tests fake Wings with `Http::fake`
  and check the second-connection read directly.
