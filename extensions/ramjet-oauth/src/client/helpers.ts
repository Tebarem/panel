/** Screen id declared in extension.json `ui.screens`. */
export const SCREEN_ID = 'clients';

/** Query key for the client list. Secrets are never cached under any key. */
export const CLIENTS_QUERY_KEY = ['ramjet-oauth', 'clients'] as const;

/** Paths of the OpenID Connect endpoints, relative to the panel origin. */
export const OIDC_PATHS = {
    discovery: '/.well-known/openid-configuration',
    authorize: '/oauth/authorize',
    token: '/oauth/token',
    userinfo: '/oauth/userinfo',
    jwks: '/oauth/jwks',
} as const;

export interface OidcEndpoint {
    id: keyof typeof OIDC_PATHS | 'issuer';
    label: string;
    url: string;
}

/** The issuer is the panel origin, so the endpoints follow the address the admin opened the panel on. */
export const oidcEndpoints = (origin: string): OidcEndpoint[] => [
    { id: 'issuer', label: 'Issuer', url: origin },
    { id: 'discovery', label: 'Discovery document', url: `${origin}${OIDC_PATHS.discovery}` },
    { id: 'authorize', label: 'Authorization endpoint', url: `${origin}${OIDC_PATHS.authorize}` },
    { id: 'token', label: 'Token endpoint', url: `${origin}${OIDC_PATHS.token}` },
    { id: 'userinfo', label: 'UserInfo endpoint', url: `${origin}${OIDC_PATHS.userinfo}` },
    { id: 'jwks', label: 'JWKS (signing keys)', url: `${origin}${OIDC_PATHS.jwks}` },
];

/** One redirect URI per line; blank lines are dropped and surrounding spaces trimmed. */
export const parseRedirectUris = (text: string): string[] =>
    text
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '');

export const formatRedirectUris = (uris: readonly string[]): string => uris.join('\n');

const MAX_URI_LENGTH = 2048;

/** Only the obvious problems are caught here; the server's rules are authoritative. */
export const validateRedirectUris = (text: string): string | undefined => {
    const uris = parseRedirectUris(text);

    if (uris.length === 0) {
        return 'Add at least one redirect URI, one per line.';
    }

    if (uris.some((uri) => /\s/.test(uri))) {
        return 'A redirect URI cannot contain spaces. Put each one on its own line.';
    }

    if (uris.some((uri) => uri.length > MAX_URI_LENGTH)) {
        return `Keep each redirect URI under ${MAX_URI_LENGTH} characters.`;
    }

    return undefined;
};

export const validateName = (value: string): string | undefined => {
    const length = value.trim().length;

    if (length === 0) {
        return 'Give the app a name.';
    }

    if (length > 191) {
        return 'Keep the name under 191 characters.';
    }

    return undefined;
};

export type FormField = 'name' | 'redirect_uris' | 'confidential' | 'skip_authorization';

const FORM_FIELDS = new Set<string>(['name', 'redirect_uris', 'confidential', 'skip_authorization']);

export interface ServerErrors {
    /** A message for the whole form, shown when no field-level message applies. */
    form: string | null;
    fields: Partial<Record<FormField, string>>;
}

// oxlint-disable rules/no-unknown-parameters, rules/no-unsafe-dictionary-type -- A failed request's body is untyped
// JSON. These guards are the only code that reads its shape, and they read nothing else.
// oxlint-disable-next-line rules/no-runtime-typeof -- Narrowing an untyped value is the purpose of this guard.
const isText = (value: unknown): value is string => typeof value === 'string';

/** The first non-empty message in a field's error list (validators and the server both put messages there). */
export const firstError = (errors: readonly unknown[]): string | undefined =>
    errors.find((error): error is string => isText(error) && error !== '');

const isRecord = (value: unknown): value is Record<string, unknown> => value instanceof Object && !Array.isArray(value);

const validationErrors = (cause: unknown): unknown[] => {
    if (!isRecord(cause) || !isRecord(cause.response) || cause.response.status !== 422) {
        return [];
    }

    const body = cause.response.data;

    return isRecord(body) && Array.isArray(body.errors) ? body.errors : [];
};

/**
 * Splits a failed save into messages for the form's fields and one for everything else. A nested source such as
 * `redirect_uris.0` is shown under `redirect_uris`.
 */
export const readServerErrors = (cause: unknown, fallback: string): ServerErrors => {
    const result: ServerErrors = { form: null, fields: {} };

    for (const error of validationErrors(cause)) {
        if (!isRecord(error)) {
            continue;
        }

        const source =
            isRecord(error.meta) && isText(error.meta.source_field) ? error.meta.source_field.split('.')[0] : '';
        const detail = isText(error.detail) ? error.detail : '';

        if (detail !== '' && FORM_FIELDS.has(source)) {
            result.fields[source as FormField] ??= detail;
        }
    }

    if (Object.keys(result.fields).length === 0) {
        result.form = fallback;
    }

    return result;
};

/**
 * Reads the endpoints a discovery document advertises. Returns undefined unless every one is present as text, so a
 * partial or foreign document falls back to the origin-built values instead of showing half a list.
 */
export const endpointsFromDiscovery = (body: unknown, discoveryUrl: string): OidcEndpoint[] | undefined => {
    if (!isRecord(body)) {
        return undefined;
    }

    const text = (key: string): string | undefined => {
        const value = body[key];

        return isText(value) && value !== '' ? value : undefined;
    };

    const issuer = text('issuer');
    const authorize = text('authorization_endpoint');
    const token = text('token_endpoint');
    const userinfo = text('userinfo_endpoint');
    const jwks = text('jwks_uri');

    if (
        issuer === undefined ||
        authorize === undefined ||
        token === undefined ||
        userinfo === undefined ||
        jwks === undefined
    ) {
        return undefined;
    }

    return [
        { id: 'discovery', label: 'Discovery document', url: discoveryUrl },
        { id: 'issuer', label: 'Issuer', url: issuer },
        { id: 'authorize', label: 'Authorization endpoint', url: authorize },
        { id: 'token', label: 'Token endpoint', url: token },
        { id: 'userinfo', label: 'UserInfo endpoint', url: userinfo },
        { id: 'jwks', label: 'JWKS (signing keys)', url: jwks },
    ];
};

// oxlint-enable rules/no-unknown-parameters, rules/no-unsafe-dictionary-type
