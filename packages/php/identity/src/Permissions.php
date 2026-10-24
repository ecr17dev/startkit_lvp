<?php

namespace Starter\Identity;

/**
 * Single catalog of permissions and system roles.
 *
 * Policies and Gates are the enforcement points; this catalog only feeds the
 * permission tables (spatie/laravel-permission) through `starter:permissions`.
 * Modules add their own permissions with Permissions::register().
 */
final class Permissions
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const EDITOR = 'editor';

    public const READER = 'reader';

    /**
     * Permissions grouped by module: name => description (translation key).
     *
     * @var array<string, array<string, string>>
     */
    private static array $groups = [
        'users' => [
            'users.read' => 'View users',
            'users.create' => 'Create users',
            'users.invite' => 'Invite users',
            'users.update' => 'Update users and their sessions',
            'users.disable' => 'Disable and enable users',
            'users.delete' => 'Delete users',
        ],
        'roles' => [
            'roles.read' => 'View roles',
            'roles.manage' => 'Create, update and delete roles',
            'permissions.read' => 'View the permission matrix',
            'permissions.manage' => 'Edit the permission matrix',
        ],
        'settings' => [
            'settings.read' => 'View system settings',
            'settings.update' => 'Update system settings',
        ],
        'mail' => [
            'mail.read' => 'View mail templates, automations and sends',
            'mail.manage' => 'Manage mail templates and automations',
        ],
        'ai' => [
            'ai.chat' => 'Use the AI chat',
            'ai.providers.manage' => 'Manage AI provider connections',
            'ai.models.manage' => 'Manage the model catalog and prices',
            'ai.agents.manage' => 'Manage agents and system prompts',
            'ai.usage.read' => 'View AI usage and costs',
            'ai.budgets.manage' => 'Manage AI budgets',
        ],
        'rag' => [
            'rag.collections.read' => 'View knowledge collections',
            'rag.collections.manage' => 'Manage knowledge collections',
            'rag.sources.manage' => 'Manage knowledge sources',
            'rag.documents.read' => 'View documents',
            'rag.documents.delete' => 'Delete documents',
            'rag.jobs.manage' => 'Manage indexing jobs',
            'rag.playground.use' => 'Use the retrieval lab',
            'rag.analytics.read' => 'View knowledge analytics',
        ],
        'operations' => [
            'audit.read' => 'View the audit trail',
            'api.docs.read' => 'View the API documentation',
            'api.tokens.create' => 'Create personal API tokens',
        ],
    ];

    /**
     * Default permissions of the system roles, other than owner (always every
     * permission) and admin (every permission except the matrix editor).
     *
     * @var array<string, list<string>>
     */
    private static array $grants = [
        self::EDITOR => [
            'users.read', 'ai.chat', 'ai.usage.read',
            'rag.collections.read', 'rag.collections.manage', 'rag.sources.manage',
            'rag.documents.read', 'rag.documents.delete', 'rag.jobs.manage',
            'rag.playground.use', 'rag.analytics.read',
            'api.docs.read', 'api.tokens.create',
        ],
        self::READER => [
            'ai.chat', 'rag.collections.read', 'rag.documents.read', 'rag.playground.use',
        ],
    ];

    /**
     * Permissions the admin role does not receive by default.
     *
     * @var list<string>
     */
    private static array $ownerOnly = ['permissions.manage'];

    /**
     * Register the permissions of a module.
     *
     * @param  array<string, string>  $permissions  name => description
     * @param  array<string, list<string>>  $grants  role => permission names
     */
    public static function register(string $group, array $permissions, array $grants = []): void
    {
        self::$groups[$group] = array_merge(self::$groups[$group] ?? [], $permissions);

        foreach ($grants as $role => $names) {
            self::$grants[$role] = array_values(array_unique(array_merge(self::$grants[$role] ?? [], $names)));
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        return self::$groups;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::$groups)));
    }

    /**
     * Get the description of a permission.
     */
    public static function description(string $permission): string
    {
        foreach (self::$groups as $permissions) {
            if (isset($permissions[$permission])) {
                return $permissions[$permission];
            }
        }

        return $permission;
    }

    /**
     * Get the system roles and their default permissions.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        $all = self::all();

        return [
            self::OWNER => $all,
            self::ADMIN => array_values(array_diff($all, self::$ownerOnly)),
            self::EDITOR => array_values(array_intersect($all, self::$grants[self::EDITOR] ?? [])),
            self::READER => array_values(array_intersect($all, self::$grants[self::READER] ?? [])),
        ];
    }

    /**
     * Get the roles that cannot be renamed nor deleted.
     *
     * @return list<string>
     */
    public static function systemRoles(): array
    {
        return [self::OWNER, self::ADMIN, self::EDITOR, self::READER];
    }
}
