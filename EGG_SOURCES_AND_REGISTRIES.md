# Egg sources and Docker registries

This document covers two admin features of this fork:

- **Docker registries** (`/admin/registries`): credentials for private image registries, attached to eggs.
- **Egg sources** (`/admin/egg-sources`): import and keep eggs in sync with JSON files in a GitHub or GitLab
  repository, or at a single URL.

Both are administrator-only.

## Setup

### Docker registries

1. Go to **Registries** and create an entry. The registry field is a lower-case host, optionally followed by a
   namespace path, with no scheme and no trailing slash:
   - `ghcr.io/myorg` (recommended: the credentials only apply to images under `ghcr.io/myorg`)
   - `docker.io/myorg` (Docker Hub namespaces must be written this way)
   - `registry.example.com:5000/team`
   - A bare host such as `ghcr.io` is accepted, but the panel warns about it: the credentials then apply to every
     image on that host.
2. Enter the username and password or access token. The password is encrypted with the panel's `APP_KEY` and is
   never displayed again. Leave it blank when editing to keep it. Changing the registry value requires entering
   the password again.
3. On an egg (**Nests → Egg → Docker Registry**), select the registry. Servers using that egg receive the
   credentials from Wings when they pull the image.

### Egg sources

1. Go to **Egg Sources** and create a source:
   - **GitHub**: `owner/repo`, a branch, and an optional directory. Every `*.json` file below the directory is
     considered.
   - **GitLab**: the full project path (for example `group/subgroup/project`), a branch, and an optional
     directory. For self-hosted GitLab, set the API base URL to the instance address.
   - **Single URL**: the full `https://` address of one egg JSON file.
   - Set the **nest** the new eggs are created in, and optionally a **registry** that is assigned to every egg
     imported from the source.
   - Use an access token for private repositories. Create a token with read-only repository scope.
2. Use **Sync now** to run a sync and see the per-file results. The hourly scheduled command
   (`p:egg-source:sync`) runs every source automatically. A single source can be synced from the command line:
   `php artisan p:egg-source:sync --id=<id>`.
3. Only JSON files that are egg exports (they have `meta.version`) are imported. Other JSON files in the
   directory are listed as *skipped*. Files that are not valid JSON, or that are larger than 1 MiB, are
   reported as failed.

## How syncing works

- A file is matched to an existing egg by the source and the file path. If there is a match, the egg is
  updated; otherwise a new egg is created. An import and its source tags are stored in one database
  transaction, so a half-imported egg cannot be left behind.
- Every source can list at most **250** JSON files. A larger location is refused, so narrow the directory path.
- Sync runs **inline**: the Wings sync for affected servers and the "Sync now" request both wait for the result.
  The scheduled sync runs in the background.
- **File rules are merged, never replaced.** For eggs updated by a source, the hidden and protected file rules
  become the union of the rules already on the egg and the rules in the repository, in that order, without
  duplicates. A sync can add patterns but cannot remove one. To remove a pattern, remove it from the
  repository and from the egg. Manual JSON imports in the admin UI keep the normal behaviour and replace the
  rules.
- When an egg's file rules or registry change, the panel asks Wings to sync every server using that egg (and
  every egg that copies its configuration from it). Failures are logged and do not stop the sync.
- **Changing the nest of a source does not move eggs that already exist.** They stay in their current nest.
  Changing the registry of a source is applied to existing eggs on the next sync.
- **Changing the path of a source creates new eggs**, because eggs are matched by file path. The old eggs are
  kept, but they are no longer synchronized.
- Deleting a source keeps the eggs that were imported from it, but they are no longer synchronized.
- Editing an egg that came from a source is allowed, but the next sync overwrites its settings from the
  repository (file rules are still merged, as above).

## Security notes

### Registry credentials and node trust

Wings needs the registry password in plain text to pull images, so it is sent to every node that runs a server
using the egg. Anyone with root access on such a node, or with the node's daemon token, can read those
credentials. Treat registry tokens as shared with every node that hosts servers of that egg:

- Use a read-only token with the narrowest scope the registry supports.
- Scope the registry entry to a namespace (`ghcr.io/myorg`), so the credentials are only sent for images in that
  namespace.
- Do not attach a registry to eggs that run on nodes you do not trust.

The panel encrypts the stored password with `APP_KEY`. If `APP_KEY` changes, stored passwords cannot be decrypted:
the affected registry is then left out of the Wings configuration and an error is logged. Re-enter the
passwords after such a change.

### Access tokens for egg sources

- Tokens are encrypted at rest and are never displayed again. Leave the field blank to keep the stored token, or
  tick **Remove the stored token**.
- A token is sent only to the API base URL (or the URL host) it was entered for. If the **type**, the **API base
  URL**, or the **host of a URL source** changes, the token must be entered again (or removed). It is never carried
  over silently.
- Tokens are only accepted with `https://` addresses. Plain `http://` is allowed only when no token is used.
- Do not put tokens or passwords in URLs. The panel rejects URLs with a user name or password, and URLs with
  `token=` or `access_token=` query parameters. Use the access token field instead.
- Error messages stored for a source have their query strings removed, so a token passed in a URL is not stored.

### Redirects

Redirects are **never followed** for egg source requests. A provider that answers with a redirect causes the sync
of that source to fail with a clear error, so an access token cannot be forwarded to another host.

### Private and internal addresses

Egg sources may reach private and LAN addresses by default, because self-hosted GitLab is commonly on a private
network. This is controlled by:

```
EGG_SOURCES_ALLOW_PRIVATE_HOSTS=true   # default
```

Set it to `false` to refuse private, loopback and other reserved addresses.

Link-local addresses and cloud metadata addresses are **always** refused, whatever the setting:

- `169.254.0.0/16` (includes `169.254.169.254`)
- `fe80::/10`
- `fd00:ec2::254`
- `100.100.100.200`

The check resolves the host name and inspects every address it returns. Hosts that do not resolve are passed to
the HTTP client, which then fails to connect. Limitation: the address is checked before the request is made, and
the HTTP client resolves the name again when it connects. A host name that changes its address between the two
lookups (DNS rebinding) is not caught. Keep egg sources pointed at hosts you control.

### Limits

- At most 250 JSON files per source, and at most 1 MiB per file (the download is stopped once the limit is passed).
- Timeouts: 5 seconds to connect and 15 seconds for each request.
- Repository names, branches and paths are validated against a strict pattern. Branches may not contain
  whitespace, `?`, `#` or `..`.

### Imported variables

Variables in an imported egg are limited to these fields: `name`, `description`, `env_variable`,
`default_value`, `user_viewable`, `user_editable`, `rules` and `sort`. Other keys in the file are ignored.

## Command reference

| Command | Purpose |
|---|---|
| `php artisan p:egg-source:sync` | Sync every egg source. |
| `php artisan p:egg-source:sync --id=<id>` | Sync one egg source. Exits with failure when the source is missing or any file fails. |

The scheduled command runs hourly with `withoutOverlapping()`.
