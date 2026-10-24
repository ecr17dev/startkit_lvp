import { router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { reactive } from 'vue';
import type { DataTableSort } from '@/components/kit/data-table';

export type TableQuery = {
    search: string;
    sort: DataTableSort | null;
    /** Extra filters; '' and 'all' are left out of the query string. */
    filters: Record<string, string>;
};

/**
 * State of a server-side DataTable kept in the query string. Changes are
 * debounced and sent with an Inertia visit that keeps the page state:
 *
 *   ?search=acme&sort=name&direction=asc&status=active
 *
 * The controller validates them and applies the sort with
 * App\Support\TableSort (allowed columns only).
 *
 * const query = useTableQuery(index().url, props.filters);
 * <DataTable server :meta="projects.meta" v-model:search="query.search" v-model:sort="query.sort" />
 */
export function useTableQuery(
    url: string | (() => string),
    current: Record<string, string | null | undefined> = {},
    options: { debounce?: number } = {},
): TableQuery {
    const { search, sort, direction, ...filters } = current;

    const state = reactive<TableQuery>({
        search: search ?? '',
        sort: sort
            ? { key: sort, direction: direction === 'desc' ? 'desc' : 'asc' }
            : null,
        filters: Object.fromEntries(
            Object.entries(filters).map(([key, value]) => [key, value ?? '']),
        ),
    });

    watchDebounced(
        state,
        () => {
            const query: Record<string, string> = {};

            if (state.search) {
                query.search = state.search;
            }

            if (state.sort) {
                query.sort = state.sort.key;
                query.direction = state.sort.direction;
            }

            for (const [key, value] of Object.entries(state.filters)) {
                if (value !== '' && value !== 'all') {
                    query[key] = value;
                }
            }

            router.get(typeof url === 'function' ? url() : url, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        },
        { debounce: options.debounce ?? 300, deep: true },
    );

    return state;
}
