import { http } from '@pterodactyl/sdk';

export interface DockerRegistry {
    id: number;
    name: string;
    registry: string;
    username: string;
    /** Eggs that pull their images with this registry. */
    egg_count: number;
    /** Human-readable notices about the registry, such as a bare host matching every image on it. */
    warnings: string[];
    created_at: string | null;
    updated_at: string | null;
}

export interface RegistryInput {
    name: string;
    registry: string;
    username: string;
    /** Required when creating. When editing, null or an empty value keeps the stored password. */
    password: string | null;
}

const BASE = '/api/admin/extensions/ramjet-eggs/registries';

/** Shared with the egg form's registry select, so both refresh together. */
export const registriesQueryKey = ['ext-ramjet-eggs', 'registries'] as const;

export const fetchRegistries = async (): Promise<DockerRegistry[]> =>
    (await http.get<{ data: DockerRegistry[] }>(BASE)).data.data;

export const createRegistry = async (input: RegistryInput): Promise<DockerRegistry> =>
    (await http.post<{ data: DockerRegistry }>(BASE, input)).data.data;

export const updateRegistry = async (id: number, input: RegistryInput): Promise<DockerRegistry> =>
    (await http.patch<{ data: DockerRegistry }>(`${BASE}/${id}`, input)).data.data;

export const deleteRegistry = async (id: number): Promise<void> => {
    await http.delete(`${BASE}/${id}`);
};
