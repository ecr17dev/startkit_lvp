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
 * Collapsible group of the sidebar (a dropdown menu when the sidebar shows
 * only icons). It opens by itself when one of its pages is active and
 * remembers whether the user left it open.
 */
export type NavGroup = {
    /** Stable id used to remember whether the group is open. */
    id: string;
    title: string;
    icon?: LucideIcon;
    items: NavItem[];
};

export type NavEntry = NavItem | NavGroup;

/**
 * Sidebar entry of a generated module (resources/js/modules/{slug}/navigation.ts).
 */
export type ModuleNavItem = NavItem & {
    /** Permission(s) required to show the entry (the backend checks again). */
    can?: string | string[];
};
