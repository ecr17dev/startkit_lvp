/**
 * Convert empty strings of a form payload into null values, so optional
 * numeric inputs are not sent as "" to the server.
 */
export function emptyToNull<T extends Record<string, unknown>>(
    data: T,
    keys: (keyof T)[],
): T {
    const result = { ...data };

    for (const key of keys) {
        if (result[key] === '') {
            result[key] = null as T[keyof T];
        }
    }

    return result;
}
