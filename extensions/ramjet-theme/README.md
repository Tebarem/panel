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

## Assets

- `archivo-latin-wdth.woff2`: Archivo variable (weight and width), SIL Open Font License 1.1 (`ARCHIVO-OFL.txt`).
- `emblem.png`: the Ramjet emblem, white on transparent.
