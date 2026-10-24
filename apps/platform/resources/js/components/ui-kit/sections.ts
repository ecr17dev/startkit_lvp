import type { LucideIcon } from '@lucide/vue';
import {
    BellRing,
    ChartColumn,
    GalleryHorizontal,
    Layers,
    ListOrdered,
    Menu,
    MousePointerClick,
    Smile,
    Table2,
    TextCursorInput,
} from '@lucide/vue';

export type CatalogSection = {
    key: string;
    title: string;
    description: string;
    icon: LucideIcon;
};

/**
 * Sections of the UI kit catalog (texts translated where shown). Keep in
 * sync with UiKitController::SECTIONS.
 */
export const catalogSections: CatalogSection[] = [
    {
        key: 'buttons',
        title: 'Buttons',
        description: 'Variants, sizes, icons, groups, toggles and loading.',
        icon: MousePointerClick,
    },
    {
        key: 'forms',
        title: 'Forms',
        description: 'Fields with label, help and errors bound to Inertia.',
        icon: TextCursorInput,
    },
    {
        key: 'wizard',
        title: 'Wizard form',
        description: 'Steps with validation per step and server errors.',
        icon: ListOrdered,
    },
    {
        key: 'tables',
        title: 'Tables',
        description: 'Search, sorting, selection and card mode on phones.',
        icon: Table2,
    },
    {
        key: 'charts',
        title: 'Charts',
        description: 'Bars, lines, areas, donut, sparklines and KPIs.',
        icon: ChartColumn,
    },
    {
        key: 'carousels',
        title: 'Carousels',
        description: 'Slides, cards per breakpoint, dots and autoplay.',
        icon: GalleryHorizontal,
    },
    {
        key: 'navigation',
        title: 'Menus and navigation',
        description: 'Dropdowns, context menu, menubar, palette and sidebar.',
        icon: Menu,
    },
    {
        key: 'feedback',
        title: 'Alerts and toasts',
        description:
            'Status messages, notifications, empty and loading states.',
        icon: BellRing,
    },
    {
        key: 'overlays',
        title: 'Modals',
        description:
            'Dialog, drawer, sheet, confirmation, popover and tooltip.',
        icon: Layers,
    },
    {
        key: 'icons',
        title: 'Icons',
        description: 'Lucide icons by name and how to use them.',
        icon: Smile,
    },
];
