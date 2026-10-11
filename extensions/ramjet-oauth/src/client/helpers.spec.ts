import { describe, expect, it } from 'vitest';
import {
    endpointsFromDiscovery,
    firstError,
    formatRedirectUris,
    oidcEndpoints,
    parseRedirectUris,
    readServerErrors,
    validateRedirectUris,
} from './helpers';

const validation = (errors: unknown[]) => ({ response: { status: 422, data: { errors } } });

describe('parseRedirectUris', () => {
    it('keeps one URI per line, trimmed, without blank lines', () => {
        expect(parseRedirectUris('  https://a.example/cb \r\n\r\nhttps://b.example/cb\n')).toEqual([
            'https://a.example/cb',
            'https://b.example/cb',
        ]);
    });

    it('round-trips through formatRedirectUris', () => {
        const uris = ['https://a.example/cb', 'myapp://callback'];

        expect(parseRedirectUris(formatRedirectUris(uris))).toEqual(uris);
    });
});

describe('validateRedirectUris', () => {
    it('asks for at least one URI', () => {
        expect(validateRedirectUris(' \n ')).toBe('Add at least one redirect URI, one per line.');
    });

    it('rejects a URI that contains a space', () => {
        expect(validateRedirectUris('https://a.example/one two')).toMatch(/cannot contain spaces/);
    });

    it('accepts a list of plain URIs', () => {
        expect(validateRedirectUris('https://a.example/cb\nmyapp://callback')).toBeUndefined();
    });
});

describe('readServerErrors', () => {
    it('places a nested source under its form field', () => {
        const errors = readServerErrors(
            validation([
                { detail: 'The redirect uris.0 field must be a valid URL.', meta: { source_field: 'redirect_uris.0' } },
            ]),
            'fallback'
        );

        expect(errors.fields).toEqual({ redirect_uris: 'The redirect uris.0 field must be a valid URL.' });
        expect(errors.form).toBeNull();
    });

    it('keeps the first message for a field', () => {
        const errors = readServerErrors(
            validation([
                { detail: 'first', meta: { source_field: 'name' } },
                { detail: 'second', meta: { source_field: 'name' } },
            ]),
            'fallback'
        );

        expect(errors.fields.name).toBe('first');
    });

    it('ignores sources the form does not show and falls back to the form message', () => {
        const errors = readServerErrors(
            validation([{ detail: 'Nope.', meta: { source_field: 'extensions.x' } }]),
            'fallback'
        );

        expect(errors).toEqual({ form: 'fallback', fields: {} });
    });

    it('falls back for a failure that is not a validation response', () => {
        expect(readServerErrors({ response: { status: 500, data: { errors: [] } } }, 'Server error.')).toEqual({
            form: 'Server error.',
            fields: {},
        });
        expect(readServerErrors(new Error('Network Error'), 'Network Error')).toEqual({
            form: 'Network Error',
            fields: {},
        });
    });
});

describe('firstError', () => {
    it('returns the first non-empty message', () => {
        expect(firstError(['', undefined, 'Bad name.', 'Later.'])).toBe('Bad name.');
        expect(firstError([])).toBeUndefined();
    });
});

describe('oidcEndpoints', () => {
    it('builds every endpoint from the panel origin', () => {
        const endpoints = oidcEndpoints('https://panel.example.com');

        expect(endpoints.map((e) => [e.id, e.url])).toEqual([
            ['issuer', 'https://panel.example.com'],
            ['discovery', 'https://panel.example.com/.well-known/openid-configuration'],
            ['authorize', 'https://panel.example.com/oauth/authorize'],
            ['token', 'https://panel.example.com/oauth/token'],
            ['userinfo', 'https://panel.example.com/oauth/userinfo'],
            ['jwks', 'https://panel.example.com/oauth/jwks'],
        ]);
    });
});

describe('endpointsFromDiscovery', () => {
    const discoveryUrl = 'https://panel.example.com/.well-known/openid-configuration';
    const document = {
        issuer: 'https://id.example.net',
        authorization_endpoint: 'https://id.example.net/oauth/authorize',
        token_endpoint: 'https://id.example.net/oauth/token',
        userinfo_endpoint: 'https://id.example.net/oauth/userinfo',
        jwks_uri: 'https://id.example.net/oauth/jwks',
    };

    it('shows the values the document advertises, not the page origin', () => {
        expect(endpointsFromDiscovery(document, discoveryUrl)).toEqual([
            { id: 'discovery', label: 'Discovery document', url: discoveryUrl },
            { id: 'issuer', label: 'Issuer', url: 'https://id.example.net' },
            { id: 'authorize', label: 'Authorization endpoint', url: 'https://id.example.net/oauth/authorize' },
            { id: 'token', label: 'Token endpoint', url: 'https://id.example.net/oauth/token' },
            { id: 'userinfo', label: 'UserInfo endpoint', url: 'https://id.example.net/oauth/userinfo' },
            { id: 'jwks', label: 'JWKS (signing keys)', url: 'https://id.example.net/oauth/jwks' },
        ]);
    });

    it('refuses a document missing any endpoint', () => {
        const { jwks_uri: _omitted, ...partial } = document;

        expect(endpointsFromDiscovery(partial, discoveryUrl)).toBeUndefined();
    });

    it('refuses a body that is not an object or has empty values', () => {
        expect(endpointsFromDiscovery('<html>', discoveryUrl)).toBeUndefined();
        expect(endpointsFromDiscovery(null, discoveryUrl)).toBeUndefined();
        expect(endpointsFromDiscovery({ ...document, issuer: '' }, discoveryUrl)).toBeUndefined();
    });
});
