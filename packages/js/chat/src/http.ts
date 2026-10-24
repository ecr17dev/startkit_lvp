/**
 * Read the XSRF token cookie set by Laravel, for requests made with fetch().
 */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * JSON request to the Laravel application with the session cookie and the
 * XSRF token. Throws with the server message when the response fails.
 */
export async function requestJson<T>(
    url: string,
    options: { method?: string; body?: unknown } = {},
): Promise<T> {
    const response = await fetch(url, {
        method: options.method ?? 'GET',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        credentials: 'same-origin',
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));

        throw new Error(data?.message || `HTTP ${response.status}`);
    }

    return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}
