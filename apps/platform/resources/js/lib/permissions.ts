/**
 * Labels (translation keys) of the permission catalog groups.
 */
export const permissionGroupLabels: Record<string, string> = {
    users: 'Users',
    roles: 'Roles and permissions',
    settings: 'System settings',
    ai: 'Artificial intelligence',
    rag: 'Knowledge (RAG)',
    operations: 'Operations',
};

/**
 * Label (translation key) of a permission group; groups of generated modules
 * fall back to their capitalized name ("projects" → "Projects").
 */
export const permissionGroupLabel = (group: string): string =>
    permissionGroupLabels[group] ??
    group.charAt(0).toUpperCase() + group.slice(1).replaceAll('_', ' ');
