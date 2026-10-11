import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        name: 'ramjet-oauth',
        environment: 'node',
        include: ['src/**/*.spec.{ts,tsx}'],
        clearMocks: true,
        restoreMocks: true,
    },
});
