// Dev-only preview of the OAuth Apps screen with the panel's real SDK, components and theme. Run from the extension
// directory with `npm run preview:ui`, then open http://localhost:5174. Nothing here is imported by the extension build.
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react-swc';

const panelRoot = fileURLToPath(new URL('../../../', import.meta.url));

export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    plugins: [react()],
    resolve: {
        alias: [
            // The SDK the panel ships, from its own source, so these are the same components a user sees.
            { find: /^@pterodactyl\/sdk$/, replacement: `${panelRoot}resources/scripts/sdk/index.ts` },
            { find: /^@pterodactyl\/sdk\/api$/, replacement: `${panelRoot}resources/scripts/sdk/api.ts` },
            { find: /^@\//, replacement: `${panelRoot}resources/scripts/` },
        ],
    },
    define: {
        'process.env.NODE_ENV': JSON.stringify('development'),
        'import.meta.env.WEBPACK_BUILD_HASH': JSON.stringify('preview'),
    },
    server: {
        host: 'localhost',
        port: 5174,
        strictPort: true,
        fs: { allow: [panelRoot] },
    },
});
