import { usePage } from '@inertiajs/vue3';

export type TranslationReplacements = Record<
    string,
    string | number | null | undefined
>;

/**
 * Translate a string using the JSON translations of the current locale
 * (lang/{locale}.json), the same files used by Laravel's __() helper.
 *
 * Placeholders use Laravel's syntax: t('Hello :name', { name: 'Ana' }).
 */
export function t(
    key: string,
    replacements: TranslationReplacements = {},
): string {
    const translations = (usePage().props.translations ?? {}) as Record<
        string,
        string
    >;

    let value = translations[key] ?? key;

    const names = Object.keys(replacements).sort((a, b) => b.length - a.length);

    for (const name of names) {
        value = value.replaceAll(`:${name}`, String(replacements[name] ?? ''));
    }

    return value;
}

/**
 * Get the active locale (e.g. "es").
 */
export function currentLocale(): string {
    return (usePage().props.locale as string | undefined) ?? 'en';
}

/**
 * Format a number of USD with the active locale.
 */
export function formatMoney(
    value: number | string | null | undefined,
    digits = 4,
): string {
    const amount = Number(value ?? 0);

    return new Intl.NumberFormat(currentLocale(), {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits:
            amount !== 0 && Math.abs(amount) < 0.01 ? digits : 2,
        maximumFractionDigits:
            amount !== 0 && Math.abs(amount) < 0.01 ? digits : 2,
    }).format(amount);
}

/**
 * Format an integer with thousands separators.
 */
export function formatNumber(
    value: number | string | null | undefined,
): string {
    return new Intl.NumberFormat(currentLocale()).format(Number(value ?? 0));
}

/**
 * Format a date (ISO string) with the active locale.
 */
export function formatDate(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions = {
        dateStyle: 'medium',
        timeStyle: 'short',
    },
): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(currentLocale(), options).format(
        new Date(value),
    );
}
