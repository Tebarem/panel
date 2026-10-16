# OAuth Apps preview (development only)

Renders the OAuth Apps admin screen in a browser with the panel's own components, theme and Tailwind build, and a
mocked admin API. It is not part of the extension bundle: `dist/client.js` never imports anything from this folder.

## Run

From `extensions/ramjet-oauth`:

```sh
npm run preview:ui
```

Then open <http://localhost:5174>. The port is fixed (`strictPort`), so stop any other process using it first.

## What it uses

- `@pterodactyl/sdk`, `@/…` and the panel's Tailwind entry come from the panel source (`resources/scripts`), not from a
  copy, so the components look and behave as they do in the panel.
- The admin API is answered in memory (`mock.ts`): three sample apps (a long confidential app with consent skipped and
  several long redirect URIs, a public app, a revoked app) and a discovery document whose issuer differs from the page
  origin, so the advertised values are the ones shown.
- Mutations (create, edit, new secret, revoke, delete) update the in-memory list, so every flow can be tried.

## Address-bar switches

| URL | Shows |
| --- | --- |
| `http://localhost:5174/` | The three sample apps and the discovery document. |
| `http://localhost:5174/?empty` | The empty state. |
| `http://localhost:5174/?discovery=fail` | The fallback warning (origin-built endpoints). |

## Checks

```sh
npm run typecheck:preview
```

Typechecks this folder against the panel's SDK source. The production build (`npm run build`) does not include it.
