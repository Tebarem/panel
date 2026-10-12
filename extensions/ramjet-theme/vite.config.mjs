import { defineExtensionConfig } from '../../packages/sdk/vite.mjs';

export default {
    ...defineExtensionConfig({ entry: 'src/client/index.tsx' }),
    // theme.css references the font and emblem; a relative base resolves them next to the
    // stylesheet (/assets/extensions/ramjet-theme/...) instead of at the panel's site root.
    base: './',
};
