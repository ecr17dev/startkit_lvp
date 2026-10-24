import createClient from 'openapi-fetch';
import type { paths } from './schema';

export type { components, operations, paths } from './schema';

export type ApiClientOptions = {
    /** Base URL of the API, e.g. https://app.example.com/api */
    baseUrl: string;
    /** Personal access token (Sanctum) sent as a Bearer token. */
    token?: string;
    fetch?: typeof fetch;
};

/**
 * Create a typed client for the starter JSON API.
 *
 * Inertia pages do not need it: use it from other applications, scripts
 * or for the JSON endpoints that are not part of an Inertia page.
 */
export function createApiClient({ baseUrl, token, fetch: customFetch }: ApiClientOptions) {
    return createClient<paths>({
        baseUrl,
        fetch: customFetch,
        headers: {
            Accept: 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });
}
