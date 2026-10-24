import { currentLocale, formatMoney, t } from '@/lib/i18n';

/**
 * Format a decimal USD amount from the ledger. Null means unknown cost,
 * never zero.
 */
export function formatCost(value: string | number | null | undefined): string {
    return value === null || value === undefined
        ? t('Unknown')
        : formatMoney(value);
}

/**
 * Format a price rate exactly as stored (up to six decimals): unlike
 * ledger amounts, a rate such as 0.025 USD per million must not be rounded.
 */
export function formatPrice(value: string | number | null | undefined): string {
    if (value === null || value === undefined) {
        return t('Unknown');
    }

    return new Intl.NumberFormat(currentLocale(), {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    }).format(Number(value));
}

export const costStatusLabels: Record<string, string> = {
    pending: 'Pending',
    estimated: 'Estimated',
    calculated: 'Calculated',
    unknown: 'Unknown',
    reconciled: 'Reconciled',
};

export const featureLabels: Record<string, string> = {
    chat: 'Chat',
    title: 'Titles',
    test: 'Tests',
    recommendation: 'Recommendations',
    lab: 'Lab',
    embedding: 'Embeddings',
    rag: 'Knowledge (RAG)',
    transcription: 'Transcription',
    tool: 'Tools',
    other: 'Other',
};

export const modelStatusVariant: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    available: 'default',
    unknown: 'outline',
    unavailable: 'destructive',
    deprecated: 'secondary',
};

export const priceComponentLabels: Record<string, string> = {
    input: 'Input',
    output: 'Output',
    cache_read: 'Cache read',
    cache_write: 'Cache write',
    reasoning: 'Reasoning',
    audio_input: 'Audio input',
    audio_seconds: 'Audio',
    request: 'Request',
    image: 'Image',
    web_search: 'Web search',
};

export const priceUnitLabels: Record<string, string> = {
    per_million_tokens: '/ 1M tokens',
    per_thousand_tokens: '/ 1K tokens',
    per_token: '/ token',
    per_minute: '/ minute',
    per_second: '/ second',
    per_request: '/ request',
    per_image: '/ image',
    per_unit: '/ unit',
};

export function formatContext(tokens: number | null): string {
    if (!tokens) {
        return '—';
    }

    return tokens >= 1_000_000
        ? `${(tokens / 1_000_000).toFixed(tokens % 1_000_000 === 0 ? 0 : 1)}M`
        : `${Math.round(tokens / 1000)}K`;
}
