import { http } from '@pterodactyl/sdk';
import { OIDC_PATHS } from './helpers';

/** Admin API prefix of this extension (`/api/admin/extensions/<id>`). */
const ADMIN = '/api/admin/extensions/ramjet-oauth';

/** Client ids are UUID strings. */
export type ClientId = string;

/** A client as the admin API lists it. Never carries the secret. */
export interface OAuthClient {
    id: ClientId;
    name: string;
    redirect_uris: string[];
    confidential: boolean;
    skip_authorization: boolean;
    revoked: boolean;
    created_at: string;
}

export interface ClientCreate {
    name: string;
    redirect_uris: string[];
    confidential: boolean;
    skip_authorization: boolean;
}

/** Confidential is fixed at creation, so an edit does not send it. */
export interface ClientUpdate {
    name: string;
    redirect_uris: string[];
    skip_authorization: boolean;
}

/**
 * The OpenID discovery document from this panel, same origin. It is public, so the body is returned untyped and
 * checked where it is read.
 */
export const fetchDiscovery = async (): Promise<unknown> => {
    const response = await fetch(OIDC_PATHS.discovery, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error(`The discovery document returned status ${response.status}.`);
    }

    const body: unknown = await response.json();

    return body;
};

export const fetchClients = async (): Promise<OAuthClient[]> =>
    (await http.get<{ data: OAuthClient[] }>(`${ADMIN}/clients`)).data.data;

/**
 * Returns the secret of a new confidential client, or null for a public one. Only the secret is returned: the
 * caller shows it once and must not keep it in the query cache or any other state.
 */
export const createClient = async (input: ClientCreate): Promise<string | null> =>
    (await http.post<{ data: { secret: string | null } }>(`${ADMIN}/clients`, input)).data.data.secret;

export const updateClient = async (id: ClientId, input: ClientUpdate): Promise<void> => {
    await http.patch(`${ADMIN}/clients/${encodeURIComponent(String(id))}`, input);
};

/** Issues a new secret, which replaces the old one and revokes the client's tokens. Returns the new secret only. */
export const regenerateClientSecret = async (id: ClientId): Promise<string> =>
    (await http.post<{ data: { secret: string } }>(`${ADMIN}/clients/${encodeURIComponent(String(id))}/secret`)).data
        .data.secret;

export const revokeClientTokens = async (id: ClientId): Promise<void> => {
    await http.post(`${ADMIN}/clients/${encodeURIComponent(String(id))}/revoke`);
};

export const deleteClient = async (id: ClientId): Promise<void> => {
    await http.delete(`${ADMIN}/clients/${encodeURIComponent(String(id))}`);
};
