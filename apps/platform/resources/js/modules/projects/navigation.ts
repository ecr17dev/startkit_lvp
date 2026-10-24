import { FolderKanban } from '@lucide/vue';
import { index } from '@/routes/projects';
import type { ModuleNavItem } from '@/types';

/**
 * Sidebar entry of the projects module (collected by AppSidebar).
 */
export default {
    title: 'Projects',
    href: index(),
    icon: FolderKanban,
    can: 'projects.read',
} satisfies ModuleNavItem;
