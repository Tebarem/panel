# Ramjet Eggs

Egg extension for the Pterodactyl panel (v2). It bundles three features; each lives in its own
namespace, migrations prefix, routes file and client module.

| Feature | Namespace | Migrations | Routes | Client |
| --- | --- | --- | --- | --- |
| Egg file rules (hidden and protected files) | `Ramjet\Eggs\Rules` | `2026_10_11_1xxxxx` | `routes/admin/rules.php` | `src/client/rules` |
| Docker registries and egg registry assignment | `Ramjet\Eggs\Registries` | `2026_10_11_2xxxxx` | `routes/admin/registries.php` | `src/client/registries` |
| Egg sources (catalog sync) | `Ramjet\Eggs\Sources` | `2026_10_11_3xxxxx` | `routes/admin/sources.php` | `src/client/sources` |

Shared pieces: `RamjetEggsProvider` (wires the registrars), `Ramjet\Eggs\Wings` (server
configuration contributor and `EggServerSync`), `extension.json` (screens and prefix), and
`routes/admin.php` (requires the three route files under `/api/admin/extensions/ramjet-eggs`).

Admin screens: `registries` (Registries, `/panel/registries`) and `sources` (Egg Sources,
`/panel/egg-sources`). Egg form extensions are registered with `forms.extend('admin.egg', ...)`.

Build the client bundle with `npx vite build` from this directory (uses the panel's `node_modules`
and the SDK preset at `packages/sdk/vite.mjs`).

## Egg file rules

Two lists per egg, edited in the admin egg form (Hidden files and Protected files, one pattern per line):

- **Hidden** (`ramjet_egg_rules.hidden`, JSON): files matching a pattern are not listed in the file manager.
- **Protected** (core `eggs.file_denylist`): files matching a pattern are listed but cannot be modified, moved,
  deleted or downloaded.

Both are sent to Wings in the server configuration as `egg.file_hidden` and `egg.file_denylist`
(`ServerConfigContributor`). Eggs with `config_from` use their parent's rules, as core does for the denylist.
Every change that alters the sent rules re-syncs the servers of the egg and of its children once committed
(`EggServerSync`, failures are logged, never thrown).

Limits: 500 patterns per list, 255 characters per pattern. Egg exports carry `file_hidden` after `file_denylist`.
Importing a file replaces the hidden rules when the file has the key and leaves them alone when it does not
(exports from before the key existed), as in 1.x.

Code: `src/Rules` (`EggRules`, `RuleSet`, `EggRulesFields`, `FilePatterns`, `Sharing/*` decorators for
export and import), `src/EggFields.php` (composes these with the registry field into the egg's one Fields
class), `src/Wings` (contributor and sync). Tests: `tests/Rules` (run with
`php vendor/bin/pest extensions/ramjet-eggs/tests/Rules` in the panel container).

## Docker registries

Credentials for private image registries, managed by root administrators on the **Registries** screen
and assigned to eggs on the egg form (**Docker registry** select). Servers of an egg receive the
credentials from Wings when it pulls their image; eggs without a registry pull anonymously.

- **Registry value**: a lower-case host with an optional port and namespace, for example `ghcr.io/myorg`,
  `docker.io/myorg` or `registry.example.com:5000/team`. No scheme, no trailing slash. Values are trimmed
  and lower-cased before they are checked. A bare host such as `ghcr.io` is accepted with a warning,
  because its credentials are then sent for every image on that host.
- **Password or token**: encrypted with `APP_KEY` (`encrypted` cast) and hidden from every response. It is
  required when a registry is created. When editing, a blank value keeps the stored one. Changing the
  registry value requires entering the password again, so a stored secret is never sent to a new host.
- If `APP_KEY` changes, stored passwords cannot be decrypted. The Wings configuration then omits that
  registry and an error is logged; re-enter the passwords.
- Deleting a registry keeps its eggs (they lose the assignment). Changing a registry's host, username or
  password, deleting it, or changing an egg's assignment asks Wings to re-sync the servers of the affected
  eggs, after the database change has committed. Renaming a registry does not re-sync.
- Registry credentials go to every node running a server of the egg. Use read-only tokens with the
  narrowest scope and do not attach a registry to eggs that run on nodes you do not trust.
- **No inheritance**: an egg's registry is its own setting. An egg that copies its configuration from
  another egg (`config_from`) does not inherit that egg's registry, unlike the file rules. This matches
  the registry field of the 1.x panel, where it was a direct egg setting. Set it on each egg that needs it.
- **Activity log**: `admin:docker-registry.create`, `.update` and `.delete` record the registry's `id`,
  `name` and `registry` only. The password and the username are never recorded.
Admin API (root administrators, `/api/admin/extensions/ramjet-eggs`):

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/registries` | `{data: [{id, name, registry, username, egg_count, warnings, created_at, updated_at}]}`, never a password |
| `POST` | `/registries` | `201 {data}`; body `{name, registry, username, password}` |
| `PATCH` | `/registries/{id}` | `200 {data}`; `password` optional, required when `registry` changes |
| `DELETE` | `/registries/{id}` | `204` |

Validation errors use the panel's error envelope (`errors[].meta.source_field`).

Code: `Ramjet\Eggs\Registries` — `EggRegistries` (`registryFor()`, `assign()`), `RegistryManager`
(create, update and delete with re-sync), `EggRegistryFields` (the egg form field, composed into
`EggFields`), models `DockerRegistry` and `EggRegistryAssignment`, and the admin controller and request.
Tests: `tests/Registries` (validation, assignment and sync, the Fields class, and the admin API).

Tests run with the panel's PHP image and database, from the panel root:
`vendor/bin/pest --configuration=<phpunit.xml>` where the configuration sets `bootstrap` to
`extensions/ramjet-eggs/tests/Registries/bootstrap.php` and the testsuite to `extensions/ramjet-eggs/tests/Registries`.
The bootstrap runs the panel's test bootstrap and then creates the registry tables before any test
starts. A schema change inside a test would commit the test transaction and leak rows between tests.

## Egg sources

Imports eggs from JSON files in a private or public GitHub or GitLab repository, or from one URL, and keeps them in
sync. Admin screen: **Egg Sources** (`/panel/egg-sources`).

- **Sources**: GitHub (`owner/repo`, branch, optional directory), GitLab (`group/project`, branch, optional
  directory, optional API base URL for self-hosted), or a single `https://` URL to one egg file. A source can attach a
  **tag** and a **Docker registry** to every egg it imports. Directories and repository names must not contain `.` or
  `..` segments.
- **Sync**: every `*.json` file below the directory with `meta.version` is imported. Other JSON files are *skipped*;
  unreadable or oversized ones *fail*. An egg is matched to its file by source and path (unique per source): a match is
  updated through the update-from-import path, otherwise the egg is created with `ImportsEggs::importParsed`. Each file
  runs in its own transaction. A file whose provider blob id (GitHub `sha`, GitLab blob `id`) has not changed since its
  last import is not downloaded again; changing the tag, registry, API base URL or location makes every file be read
  again.
- **Running a sync**: "Sync now" queues `SyncEggSourceJob` (one per source, `ShouldBeUnique`) and answers `202`. The
  source reports `syncing` while the job is queued or running, and the admin screen polls until it stops; the per-file
  results are stored on the source (`last_sync_results`). Each source is locked while it syncs, so a second run is
  refused rather than interleaved. The `ramjet-eggs:sync [--id=]` command runs synchronously, and runs every source
  hourly (`withoutOverlapping`).
- **File rules are a union**: an update keeps the rules already on the egg and adds the repository's, in that order
  (`EggRules::set` the previous rules when they changed, then `EggRules::merge` the file's). The union must stay within
  the file pattern limits (`FilePatterns`), otherwise the file fails and nothing of it is kept. The hidden list is read
  from the raw JSON because the import data does not carry it. Wings is re-synced by `EggRules` and `EggRegistries`
  after commit, so an unchanged second sync sends nothing to Wings.
- **Tokens** are write-only and encrypted. They are sent only over `https://`, and only to the origin they were entered
  for (scheme, host and port). Changing the provider, API base URL or URL origin requires the token to be entered again
  or removed. Tokens go in the `Authorization: Bearer` header (GitHub) or `PRIVATE-TOKEN` (GitLab), never in a URL.
- **Addresses**: only plain DNS names and literal IP addresses are accepted. Forms that libcurl would parse itself
  (`0xa9fea9fe`, `2852039166`, `127.1`, a trailing dot, scope ids) are refused. Every address a name resolves to is
  checked, and a name that resolves to nothing is refused. IPv4-mapped, NAT64 (`64:ff9b::/96`) and IPv4-compatible IPv6
  addresses are checked as the IPv4 address they carry. Link-local (`169.254.0.0/16`, `fe80::/10`), metadata
  (`100.100.100.200`, `fd00:ec2::254`) and unspecified addresses are **always** refused. Private, loopback and reserved
  addresses are refused unless the extension setting `allow_private_hosts` is enabled; it is **off** by default.
- **DNS rebinding**: the address the checks passed is pinned for the request (`CURLOPT_RESOLVE`), so the HTTP client does
  not resolve the name a second time. The resolver is the `HostResolver` interface, bound by the registrar.
- **Hardening** (ported from 1.x): redirects are never followed; URLs with a user name or password are refused; query
  strings are stripped from stored error messages; per-type repository and branch patterns; at most 250 JSON files per
  source. Listings are capped at 10 MiB and files at 1 MiB, both read as streams and cut off at the limit; paging stops
  once a listing is over the file limit. Error responses are not read. Timeouts: 5 s connect and 15 s per request.
- **Activity**: creating, updating, deleting and syncing a source are logged (`egg-source:*`) with its id, name, type,
  host and whether a token is stored. The token is never logged.

Routes (`/api/admin/extensions/ramjet-eggs`): `GET|POST /sources`, `PATCH|DELETE /sources/{id}`,
`POST /sources/{id}/sync` (202). Tests: `tests/Sources` (admin API through the real routes, sync service with
`Http::fake` and a stub resolver, hardening cases, sync command). The queue must be processed by a worker in production.
