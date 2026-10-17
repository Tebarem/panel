import { defineConfig } from 'vitest/config';
import { pterodactylTestAliases } from '../../packages/sdk/vitest.mjs';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    resolve: {
        alias: pterodactylTestAliases(),
    },
    server: {
        // The SDK's test runtime and setup live in the panel's packages directory.
        fs: { allow: [fileURLToPath(new URL('../..', import.meta.url))] },
    },
    test: {
        name: 'ramjet-proxy-provisioning',
        environment: 'jsdom',
        setupFiles: ['../../packages/sdk/vitest-setup.mjs'],
        include: ['src/**/*.spec.{ts,tsx}'],
        clearMocks: true,
        restoreMocks: true,
    },
});
