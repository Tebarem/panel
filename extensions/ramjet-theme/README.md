# Ramjet Theme

The ramjet.gg look for the panel: near-black surfaces, afterburner-orange brand colour, Archivo
headings and the Ramjet emblem in the top bar and on the sign-in screens.

It is built from design-token overrides (`src/client/theme.css`), the same approach as the official
dark-theme extension, so it keeps working as core components change. The only component selectors
are the two brand marks: `#logo a` (top bar) and `svg[aria-label='Pterodactyl']` (sign-in screens).
The colours mirror the website's tokens in the `ramjet` repo (`src/styles.css`).

## Build

```bash
cd extensions/ramjet-theme
npx vite build        # uses the panel's node_modules and the SDK preset
npm run contrast      # WCAG check of every text/surface pair; exits 1 on failure
```

`vite.config.mjs` sets `base: './'` so the font and emblem resolve next to the stylesheet under
`/assets/extensions/ramjet-theme/`.

Stage `infra/40-panel.sh` builds and enables it with the other Ramjet extensions. Disable it with
`php artisan p:extension:disable ramjet-theme` to get the stock look back.

## Notification emails

The panel's notification emails (account created, password reset, server installed, added or removed as a
subuser, mail test) use the Ramjet look: a dark header band with the emblem, a light content card, an
afterburner-orange button (red for error-level mail) and a dark footer. The templates are in
`resources/views/notifications/` and override core's copies while the extension is enabled
(`RamjetThemeProvider::boot()` prepends the namespace, so disabling the extension restores core's emails).

- `email.blade.php`: the HTML part. Table layout, inline styles, a VML button for Outlook, and a dark-mode
  media query. The preheader is the first intro line.
- `email-plain.blade.php`: the text part. Laravel renders a mail's text part from the HTML view name, so core's
  text part was its HTML source. `Ramjet\Theme\Email\TextPartMarkdown` sends the text part through this template
  instead. HTML rendering is unchanged.
- The emblem is served at `/extensions/ramjet-theme/email/emblem.png` (80px) and `emblem@2x.png` (160px) with a
  one-year cache, because mail clients fetch it from outside the panel. The files are in `resources/email/`.
  The image URLs are absolute, built from `APP_URL`.

### Previewing the emails

```bash
php artisan ramjet-theme:email-preview /path/to/out            # logos point at APP_URL
php artisan ramjet-theme:email-preview /path/to/out --inline-logo  # logos embedded, renders offline
```

This writes `<name>.html` and `<name>.txt` for each notification, plus `index.html`. It sends nothing and
writes nothing to the database. The messages are built from in-memory models
(`Ramjet\Theme\Email\PreviewNotifications`).

### Tests

```bash
SKIP_MIGRATIONS=1 vendor/bin/pest -c extensions/ramjet-theme/tests/phpunit.xml
```

The panel's test database must be named with "test" (see `bootstrap/tests.php`). The tests render every
notification through the theme, check the brand colours, the emblem URL, the call to action in both parts,
escaping of a hostile name, the plain-text part, and that core's template is used when the theme is not
booted.

## Assets

- `archivo-latin-wdth.woff2`: Archivo variable (weight and width), SIL Open Font License 1.1 (`ARCHIVO-OFL.txt`).
- `emblem.png`: the Ramjet emblem, white on transparent.
