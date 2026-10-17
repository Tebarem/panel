import { describe, expect, it, vi } from 'vitest';
import type { ExtensionSetupContext } from '@pterodactyl/sdk';
import extension from './index';

vi.mock('./api', () => ({
    proxyStatusQueryKey: ['ext-ramjet-proxy-provisioning', 'status'],
    fetchProxyStatus: vi.fn(async () => ({ enabled: true, has_proxy: false, server: null, can_create: true })),
    createProxy: vi.fn(),
    isDashboardServersQuery: vi.fn(() => false),
}));

/** Mirrors the panel's registry: a slot takes one component per anchor, and a second registration fails. */
function recordingContext() {
    const slots = new Map<string, unknown>();
    const context: ExtensionSetupContext = {
        meta: { id: 'ramjet-proxy-provisioning' },
        config: {},
        components: { replace: vi.fn() },
        slots: {
            register: vi.fn((name: string, component: unknown) => {
                if (slots.has(name)) throw new Error(`Duplicate slot: ${name}`);
                slots.set(name, component);
            }),
        },
        screens: { register: vi.fn() },
        columns: { register: vi.fn() },
        forms: { extend: vi.fn() },
    };

    return { context, slots };
}

describe('ramjet-proxy-provisioning client registration', () => {
    it('sets up without throwing and mounts the proxy card above the dashboard server list', () => {
        const { context, slots } = recordingContext();

        expect(() => extension.setup(context)).not.toThrow();

        expect(context.slots.register).toHaveBeenCalledTimes(1);
        expect(context.slots.register).toHaveBeenCalledWith('dashboard.before', expect.any(Function));
        expect([...slots.keys()]).toEqual(['dashboard.before']);
        expect(context.screens.register).not.toHaveBeenCalled();
    });
});
