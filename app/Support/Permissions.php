<?php

namespace App\Support;

/**
 * Central catalog of the permissions and default roles of the application.
 *
 * Add new permissions here and run `php artisan permissions:sync`
 * (or `php artisan db:seed --class=RolesAndPermissionsSeeder`).
 */
final class Permissions
{
    public const SUPER_ADMIN = 'super-admin';

    public const ADMIN = 'admin';

    public const USER = 'user';

    /**
     * Permissions grouped by module. The array keys are the permission
     * names and the values a short description shown in the UI.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        return [
            'users' => [
                'users.view' => 'View users',
                'users.create' => 'Create users',
                'users.update' => 'Update users',
                'users.delete' => 'Delete users',
            ],
            'roles' => [
                'roles.view' => 'View roles',
                'roles.create' => 'Create roles',
                'roles.update' => 'Update roles',
                'roles.delete' => 'Delete roles',
            ],
            'settings' => [
                'settings.general' => 'Manage general settings',
                'settings.branding' => 'Manage branding',
                'settings.mail' => 'Manage mail server',
                'settings.auth' => 'Manage authentication settings',
            ],
            'ai' => [
                'ai.chat' => 'Use the AI chat',
                'ai.choose-model' => 'Choose provider and model in the chat',
                'ai.providers.manage' => 'Manage AI providers and models',
                'ai.prompts.manage' => 'Manage system prompts',
                'ai.usage.view' => 'View AI usage and costs',
            ],
            'api' => [
                'api.tokens' => 'Create personal API tokens',
                'api.docs' => 'View the API documentation',
            ],
        ];
    }

    /**
     * Get every permission name.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::grouped())));
    }

    /**
     * Get the default roles and their permissions.
     *
     * The super admin role bypasses every check through Gate::before().
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        return [
            self::SUPER_ADMIN => [],
            self::ADMIN => self::all(),
            self::USER => ['ai.chat', 'api.tokens'],
        ];
    }

    /**
     * Get the roles that cannot be renamed nor deleted.
     *
     * @return list<string>
     */
    public static function protectedRoles(): array
    {
        return [self::SUPER_ADMIN, self::ADMIN, self::USER];
    }
}
