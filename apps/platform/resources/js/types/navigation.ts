import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

/**
 * Sidebar entry of a generated module (resources/js/modules/{slug}/navigation.ts).
 */
export type ModuleNavItem = NavItem & {
    /** Permission(s) required to show the entry (the backend checks again). */
    can?: string | string[];
};
