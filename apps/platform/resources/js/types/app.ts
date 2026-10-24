export type Branding = {
    appName: string;
    tagline: string | null;
    logoUrl: string | null;
    logoDarkUrl: string | null;
    faviconUrl: string | null;
    authLayout: 'simple' | 'card' | 'split';
    defaultAppearance: 'light' | 'dark' | 'system';
};

export type Features = {
    passwordLogin: boolean;
    registration: boolean;
    organizations: boolean;
    ai: boolean;
    rag: boolean;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginationMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    links: PaginationLink[];
    per_page: number;
    to: number | null;
    total: number;
};

/**
 * A Laravel API resource collection created from a paginator.
 */
export type Paginated<T> = {
    data: T[];
    meta: PaginationMeta;
};

/**
 * A Laravel paginator serialized directly (without API resources).
 */
export type SimplePaginated<T> = PaginationMeta & {
    data: T[];
};
