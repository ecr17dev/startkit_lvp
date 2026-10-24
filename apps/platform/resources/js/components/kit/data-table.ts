/**
 * Column definition of the kit DataTable (components/kit/DataTable.vue).
 */
export type DataTableColumn<T> = {
    /** Property of the row ("name", "owner.email") and id of the column. */
    key: string;
    label: string;
    sortable?: boolean;
    align?: 'left' | 'center' | 'right';
    /** Hide the column in table mode below this breakpoint. */
    hideBelow?: 'sm' | 'md' | 'lg' | 'xl';
    /** Start hidden (the user can show it from the columns menu). */
    hidden?: boolean;
    /** Text of the cell (and of the search) when there is no slot. */
    format?: (value: unknown, row: T) => string;
    /** Value used to sort and search (defaults to the property). */
    value?: (row: T) => unknown;
    /**
     * Place in card mode: the card title, a subtitle under it, a badge in
     * the corner, a labelled field (default) or nowhere.
     */
    card?: 'title' | 'subtitle' | 'badge' | 'field' | 'hidden';
    /** Extra classes of the cells. */
    class?: string;
};

export type DataTableSort = { key: string; direction: 'asc' | 'desc' };

export type DataTableMode = 'table' | 'cards' | 'auto';

/** Read a (possibly nested) property of a row. */
export function readPath(row: unknown, path: string): unknown {
    return path
        .split('.')
        .reduce<unknown>(
            (value, key) =>
                value !== null && typeof value === 'object'
                    ? (value as Record<string, unknown>)[key]
                    : undefined,
            row,
        );
}

/** Compare two cell values: numbers numerically, text naturally, empty last. */
export function compareValues(a: unknown, b: unknown, locale: string): number {
    const empty = (value: unknown) =>
        value === null || value === undefined || value === '';

    if (empty(a) || empty(b)) {
        return empty(a) === empty(b) ? 0 : empty(a) ? 1 : -1;
    }

    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    if (typeof a === 'boolean' && typeof b === 'boolean') {
        return Number(a) - Number(b);
    }

    const text = (value: unknown) =>
        typeof value === 'string' ? value : JSON.stringify(value);

    return text(a).localeCompare(text(b), locale, {
        numeric: true,
        sensitivity: 'base',
    });
}
