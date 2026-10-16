/**
 * Preview-only API. It answers the admin API and the discovery document from memory, so the screen can be exercised
 * without the panel's backend. Nothing here is part of the extension bundle.
 *
 * Address-bar switches: `?empty` shows the empty state; `?discovery=fail` makes the discovery document fail.
 */
import type { AxiosAdapter, AxiosResponse, InternalAxiosRequestConfig } from 'axios';
import { http } from '@pterodactyl/sdk';

const ADMIN = '/api/admin/extensions/ramjet-oauth';
const DISCOVERY = '/.well-known/openid-configuration';

interface MockClient {
    id: string;
    name: string;
    redirect_uris: string[];
    confidential: boolean;
    skip_authorization: boolean;
    revoked: boolean;
    created_at: string;
}

interface ClientBody {
    name?: string;
    redirect_uris?: string[];
    confidential?: boolean;
    skip_authorization?: boolean;
}

const daysAgo = (days: number, hours = 0): string =>
    new Date(Date.now() - days * 86_400_000 - hours * 3_600_000).toISOString();

// Three samples: a long confidential first-party app with several long redirect URIs and consent skipped, a public
// app, and a revoked one.
const clients: MockClient[] = [
    {
        id: '01a12dc9-cd2c-702a-afed-8ee180829693',
        name: 'Billing portal for the Acme reconciliation service (staging and production)',
        redirect_uris: [
            'https://billing.acme-internal.example.net/oauth/callback/primary-region',
            'https://billing-eu.acme-internal.example.net/oauth/callback/eu-west-1',
            'http://localhost:8080/auth/ramjet/callback/development',
        ],
        confidential: true,
        skip_authorization: true,
        revoked: false,
        created_at: daysAgo(3),
    },
    {
        id: '5c0e7f2a-9b41-4d8e-8a6c-3f1d2e7b9a10',
        name: 'Mobile companion',
        redirect_uris: ['myapp://callback'],
        confidential: false,
        skip_authorization: false,
        revoked: false,
        created_at: daysAgo(0, 2),
    },
    {
        id: 'e47b2c91-3a6d-4f08-b5e2-9d1c8a7f6b42',
        name: 'Old reporting script',
        redirect_uris: ['https://reports.example.org/callback'],
        confidential: true,
        skip_authorization: false,
        revoked: true,
        created_at: daysAgo(41),
    },
];

const delay = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const query = (name: string): boolean => new URLSearchParams(window.location.search).has(name);

interface ErrorBody {
    code: string;
    status: string;
    detail: string;
}

/** The shapes the preview answers with: a client or client list under data, an error list, or an empty 204 body. */
type ResponseBody =
    | { data: MockClient | MockClient[] | (MockClient & { secret: string | null }) }
    | { errors: ErrorBody[] }
    | '';

const respond = (config: InternalAxiosRequestConfig, status: number, data: ResponseBody): AxiosResponse => ({
    data,
    status,
    statusText: status === 204 ? 'No Content' : 'OK',
    headers: {},
    config,
    request: {},
});

const notFound = (config: InternalAxiosRequestConfig): AxiosResponse =>
    respond(config, 404, { errors: [{ code: 'not_found', status: '404', detail: 'Not found in the preview.' }] });

const randomSecret = (): string =>
    Array.from(crypto.getRandomValues(new Uint8Array(20)), (byte) => byte.toString(16).padStart(2, '0')).join('');

const readBody = (config: InternalAxiosRequestConfig): ClientBody =>
    JSON.parse(String(config.data ?? '{}')) as ClientBody;

const createClient = (config: InternalAxiosRequestConfig): AxiosResponse => {
    const body = readBody(config);
    const confidential = body.confidential === true;
    const client: MockClient = {
        id: crypto.randomUUID(),
        name: body.name ?? '',
        redirect_uris: body.redirect_uris ?? [],
        confidential,
        skip_authorization: body.skip_authorization === true,
        revoked: false,
        created_at: new Date().toISOString(),
    };

    clients.unshift(client);

    return respond(config, 201, { data: { ...client, secret: confidential ? randomSecret() : null } });
};

const updateClient = (client: MockClient, config: InternalAxiosRequestConfig): AxiosResponse => {
    const body = readBody(config);

    client.name = body.name ?? client.name;
    client.redirect_uris = body.redirect_uris ?? client.redirect_uris;
    client.skip_authorization = body.skip_authorization === true;

    return respond(config, 200, { data: client });
};

const clientRoute = /^\/api\/admin\/extensions\/ramjet-oauth\/clients\/([^/]+)(\/secret|\/revoke)?$/;

const routeClient = (config: InternalAxiosRequestConfig, method: string, url: string): AxiosResponse => {
    const match = clientRoute.exec(url);
    const client = match ? clients.find((candidate) => candidate.id === match[1]) : undefined;

    if (!match || !client) {
        return notFound(config);
    }

    const action = match[2];

    if (method === 'PATCH' && action === undefined) {
        return updateClient(client, config);
    }

    if (method === 'POST' && action === '/secret') {
        return respond(config, 200, { data: { ...client, secret: randomSecret() } });
    }

    if (method === 'POST' && action === '/revoke') {
        return respond(config, 204, '');
    }

    if (method === 'DELETE' && action === undefined) {
        clients.splice(clients.indexOf(client), 1);

        return respond(config, 204, '');
    }

    return notFound(config);
};

const adapter: AxiosAdapter = async (config) => {
    await delay(250);

    const method = (config.method ?? 'get').toUpperCase();
    const url = (config.url ?? '').split('?')[0];

    if (url === `${ADMIN}/clients` && method === 'GET') {
        // ?empty shows the empty state. The samples are untouched, so the default URL still shows them.
        return respond(config, 200, { data: query('empty') ? [] : clients });
    }

    if (url === `${ADMIN}/clients` && method === 'POST') {
        return createClient(config);
    }

    return routeClient(config, method, url);
};

const discoveryDocument = {
    // Deliberately not this page's origin, so the preview shows the advertised issuer rather than the fallback.
    issuer: 'https://id.ramjet.example',
    authorization_endpoint: 'https://id.ramjet.example/oauth/authorize',
    token_endpoint: 'https://id.ramjet.example/oauth/token',
    userinfo_endpoint: 'https://id.ramjet.example/oauth/userinfo',
    jwks_uri: 'https://id.ramjet.example/oauth/jwks',
};

const pathOf = (input: RequestInfo | URL): string => {
    if (input instanceof Request) {
        return new URL(input.url, window.location.origin).pathname;
    }

    return new URL(String(input), window.location.origin).pathname;
};

const discoveryResponse = (): Response =>
    query('discovery')
        ? new Response('<!doctype html><html>not json</html>', { status: 500 })
        : new Response(JSON.stringify(discoveryDocument), {
              status: 200,
              headers: { 'Content-Type': 'application/json' },
          });

export function installMockApi(): void {
    http.defaults.adapter = adapter;

    const realFetch = window.fetch.bind(window);

    window.fetch = async (input, init) => {
        if (pathOf(input) === DISCOVERY) {
            await delay(300);

            return discoveryResponse();
        }

        return realFetch(input, init);
    };
}
