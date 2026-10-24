import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Check the permissions of the authenticated user on the frontend.
 *
 * This is only a UX helper: every action is authorized again on the server.
 */
export function usePermissions() {
    const page = usePage();

    const permissions = computed<string[]>(
        () => page.props.auth?.permissions ?? [],
    );

    const roles = computed<string[]>(() => page.props.auth?.user?.roles ?? []);

    const can = (permission: string | string[]): boolean => {
        const list = Array.isArray(permission) ? permission : [permission];

        return list.some((name) => permissions.value.includes(name));
    };

    const hasRole = (role: string): boolean => roles.value.includes(role);

    return { permissions, roles, can, hasRole };
}
