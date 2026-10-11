import { describe, expect, it, vi } from 'vitest';
import type { ExtensionSetupContext } from '@pterodactyl/sdk';
import extension from './index';

vi.mock('./registries/api', () => ({
    registriesQueryKey: ['ramjet-eggs', 'registries'],
    fetchRegistries: vi.fn(async () => []),
}));

/**
 * Mirrors the panel's registry: a form can be extended once per extension, and a second registration
 * for the same form fails with "Duplicate component".
 */
function recordingContext() {
    const forms = new Map<string, unknown>();
    const screens = new Map<string, unknown>();
    const context: ExtensionSetupContext = {
        meta: { id: 'ramjet-eggs' },
        config: {},
        components: { replace: vi.fn() },
        slots: { register: vi.fn() },
        columns: { register: vi.fn() },
        screens: {
            register: vi.fn((id: string, importer: unknown) => {
                if (screens.has(id)) throw new Error(`Duplicate screen: ${id}`);
                screens.set(id, importer);
            }),
        },
        forms: {
            extend: vi.fn((form: string, component: unknown) => {
                if (forms.has(form)) throw new Error('Duplicate component');
                forms.set(form, component);
            }),
        },
    };

    return { context, forms, screens };
}

describe('ramjet-eggs client registration', () => {
    it('sets up without throwing and registers both screens and the egg form extension once', () => {
        const { context, forms, screens } = recordingContext();

        expect(() => extension.setup(context)).not.toThrow();

        expect(context.forms.extend).toHaveBeenCalledTimes(1);
        expect(context.forms.extend).toHaveBeenCalledWith('admin.egg', expect.any(Function));
        expect([...forms.keys()]).toEqual(['admin.egg']);
        expect([...screens.keys()].sort()).toEqual(['registries', 'sources']);
    });
});
