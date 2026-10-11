import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import EggFormFields from './EggFormFields';

vi.mock('./registries/api', () => ({
    registriesQueryKey: ['ramjet-eggs', 'registries'],
    fetchRegistries: vi.fn(async () => []),
}));

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
});

it('draws the hidden, protected and registry fields bound to the egg form', async () => {
    host = createExtensionTestHost();
    const values = { file_hidden: 'secret.txt', file_denylist: 'eula.txt', registry_id: null };
    const setValue = vi.fn();
    const field = (name: keyof typeof values) => ({
        id: `field-${name}`,
        name: `extensions.ramjet-eggs.${name}`,
        value: values[name],
        setValue,
        error: undefined,
    });

    render(<EggFormFields form={'admin.egg'} mode={'edit'} values={values} field={field as never} />, { wrapper: host.Wrapper });

    expect(await screen.findByLabelText('Hidden files')).toHaveValue('secret.txt');
    expect(screen.getByLabelText('Protected files')).toHaveValue('eula.txt');
    expect(screen.getByText('Docker registry')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Protected files'), { target: { value: 'eula.txt\nserver.properties' } });
    expect(setValue).toHaveBeenCalledWith('eula.txt\nserver.properties');
});
