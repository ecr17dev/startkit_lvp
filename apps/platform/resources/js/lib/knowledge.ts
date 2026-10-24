import { t } from '@/lib/i18n';

type Variant = 'default' | 'secondary' | 'outline' | 'destructive';

export const jobStatusVariant: Record<string, Variant> = {
    queued: 'outline',
    running: 'secondary',
    retrying: 'secondary',
    paused: 'outline',
    succeeded: 'default',
    failed: 'destructive',
    cancelled: 'outline',
};

export const jobStatusLabels: Record<string, string> = {
    queued: 'Queued',
    running: 'Running',
    retrying: 'Retrying',
    paused: 'Paused',
    succeeded: 'Succeeded',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

export const jobTypeLabels: Record<string, string> = {
    sync_source: 'Source sync',
    index_document: 'Document indexing',
    reindex: 'Reindex',
    purge_document: 'Document purge',
    purge_index_version: 'Version purge',
    purge_source: 'Source deletion',
    purge_collection: 'Collection deletion',
};

export const stageLabels: Record<string, string> = {
    discover: 'Discover',
    fetch: 'Read',
    validate: 'Validate',
    extract: 'Extract',
    normalize: 'Normalize',
    dedupe: 'Deduplicate',
    chunk: 'Chunk',
    embed: 'Embeddings',
    persist: 'Persist',
    publish: 'Publish',
    verify: 'Verify',
    purge: 'Purge',
};

export const documentStatusVariant: Record<string, Variant> = {
    pending: 'outline',
    indexing: 'secondary',
    indexed: 'default',
    failed: 'destructive',
    empty: 'outline',
    duplicate: 'outline',
    deleted: 'outline',
};

export const documentStatusLabels: Record<string, string> = {
    pending: 'Pending',
    indexing: 'Indexing',
    indexed: 'Indexed',
    failed: 'Failed',
    empty: 'No text',
    duplicate: 'Duplicate',
    deleted: 'Deleted',
};

export const sourceTypeLabels: Record<string, string> = {
    upload: 'Files',
    url: 'Web pages',
    sitemap: 'Sitemap',
    adapter: 'Internal content',
    disk: 'Storage disk',
};

export const versionStatusVariant: Record<string, Variant> = {
    building: 'secondary',
    active: 'default',
    superseded: 'outline',
    failed: 'destructive',
    discarded: 'outline',
};

export const versionStatusLabels: Record<string, string> = {
    building: 'Building',
    active: 'Active',
    superseded: 'Superseded',
    failed: 'Failed',
    discarded: 'Discarded',
};

export const methodLabels: Record<string, string> = {
    vector: 'Vector',
    text: 'Full text',
    hybrid: 'Hybrid (RRF)',
};

export const languageLabels: Record<string, string> = {
    simple: 'Neutral (no stemming)',
    es: 'Spanish',
    en: 'English',
    pt: 'Portuguese',
    fr: 'French',
    de: 'German',
    it: 'Italian',
};

export const scheduleLabels: Record<string, string> = {
    hourly: 'Every hour',
    daily: 'Every day',
    weekly: 'Every week',
};

export function formatBytes(bytes: number | null | undefined): string {
    const value = Number(bytes ?? 0);

    if (value < 1024) {
        return `${value} B`;
    }

    if (value < 1024 * 1024) {
        return `${(value / 1024).toFixed(1)} KB`;
    }

    return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

export function formatMs(ms: number | null | undefined): string {
    const value = Number(ms ?? 0);

    return value < 1000 ? `${value} ms` : `${(value / 1000).toFixed(1)} s`;
}

export function principalLabel(principal: string): string {
    if (principal === 'everyone') {
        return t('Every authenticated user');
    }

    const [kind, value] = principal.split(/:(.*)/s);

    return `${t(kind === 'role' ? 'Role' : kind === 'user' ? 'User' : 'Permission')}: ${value}`;
}

export function isOpenJob(status: string): boolean {
    return ['queued', 'running', 'retrying', 'paused'].includes(status);
}
