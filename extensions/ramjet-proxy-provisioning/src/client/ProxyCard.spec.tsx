import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import ProxyCard from './ProxyCard';
import { createProxy, fetchProxyStatus, isDashboardServersQuery, proxyStatusQueryKey } from './api';

vi.mock('./api', () => ({
    proxyStatusQueryKey: ['ext-ramjet-proxy-provisioning', 'status'],
    fetchProxyStatus: vi.fn(),
    createProxy: vi.fn(),
    isDashboardServersQuery: vi.fn(() => false),
}));

const DESCRIPTION = 'A lightweight Ramjet Proxy (Velocity-compatible) for your servers';

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
});

const CAN_CREATE = { enabled: true, has_proxy: false, server: null, can_create: true };

/** Opens the create dialog and confirms it, as the customer does. */
async function confirmCreate(): Promise<void> {
    fireEvent.click(await screen.findByRole('button', { name: 'Create proxy' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create proxy' }));
}

it('offers the proxy with an enabled create button when the customer has none', async () => {
    vi.mocked(fetchProxyStatus).mockResolvedValue(CAN_CREATE);
    host = createExtensionTestHost();

    render(<ProxyCard />, { wrapper: host.Wrapper });

    expect(await screen.findByText(DESCRIPTION)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create proxy' })).toBeEnabled();
});

it('renders nothing while the feature is disabled', async () => {
    vi.mocked(fetchProxyStatus).mockResolvedValue({ enabled: false, has_proxy: false, server: null, can_create: false });
    host = createExtensionTestHost();

    render(<ProxyCard />, { wrapper: host.Wrapper });

    await vi.waitFor(() => expect(fetchProxyStatus).toHaveBeenCalled());
    expect(screen.queryByText(DESCRIPTION)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Create proxy' })).not.toBeInTheDocument();
});

it('explains why the proxy cannot be created yet and disables the button', async () => {
    vi.mocked(fetchProxyStatus).mockResolvedValue({
        enabled: true,
        has_proxy: false,
        server: null,
        can_create: false,
        reason: 'No free port is available on the node right now. Try again later.',
    });
    host = createExtensionTestHost();

    render(<ProxyCard />, { wrapper: host.Wrapper });

    expect(await screen.findByText('No free port is available on the node right now. Try again later.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create proxy' })).toBeDisabled();
});

it('creating a proxy invalidates the proxy status and the dashboard server list', async () => {
    vi.mocked(fetchProxyStatus).mockResolvedValue(CAN_CREATE);
    vi.mocked(createProxy).mockResolvedValue({ identifier: 'abc12345', name: 'alice-proxy', uuidShort: 'abc12345' });
    host = createExtensionTestHost();
    const invalidate = vi.spyOn(host.queryClient, 'invalidateQueries');

    render(<ProxyCard />, { wrapper: host.Wrapper });
    await confirmCreate();

    await vi.waitFor(() => expect(createProxy).toHaveBeenCalledTimes(1));
    await vi.waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: proxyStatusQueryKey }));
    expect(invalidate).toHaveBeenCalledWith({ predicate: isDashboardServersQuery });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('a refused creation shows its message inline and still invalidates the proxy status', async () => {
    vi.mocked(fetchProxyStatus).mockResolvedValue(CAN_CREATE);
    vi.mocked(createProxy).mockRejectedValue(new Error('The proxy node is full right now. Try again later.'));
    host = createExtensionTestHost();
    const invalidate = vi.spyOn(host.queryClient, 'invalidateQueries');

    render(<ProxyCard />, { wrapper: host.Wrapper });
    await confirmCreate();

    expect(await screen.findByText('The proxy node is full right now. Try again later.')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('The proxy node is full right now. Try again later.');
    expect(invalidate).toHaveBeenCalledWith({ queryKey: proxyStatusQueryKey });
    expect(invalidate).not.toHaveBeenCalledWith({ predicate: isDashboardServersQuery });
});
