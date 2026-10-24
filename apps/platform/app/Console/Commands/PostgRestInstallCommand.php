<?php

namespace App\Console\Commands;

use App\PostgRest\SchemaInstaller;
use Illuminate\Console\Command;

/**
 * Prepare the objects PostgREST may publish (schema api, views, RPC) and,
 * with privileges, its roles and grants. Without CREATEROLE it prints the
 * SQL for the database administrator. Run it again after migrations: the
 * views are replaced and PostgREST reloads its schema cache.
 */
class PostgRestInstallCommand extends Command
{
    protected $signature = 'starter:postgrest-install
        {--password= : Password of the authenticator role (creates the roles; or POSTGREST_DB_PASSWORD)}
        {--sql : Only print the SQL (schema, roles and grants) for an administrator}';

    protected $description = 'Prepare the PostgREST api schema, roles and grants';

    public function handle(SchemaInstaller $installer): int
    {
        $password = $this->option('password') ?? (config('starter.postgrest.db_password') ?: null);

        if ($password !== null && ! preg_match('/^[A-Za-z0-9_\-.:@#%+=]{12,128}$/', (string) $password)) {
            $this->components->error('The password needs 12-128 characters among letters, digits and _-.:@#%+=');

            return self::FAILURE;
        }

        if ($this->option('sql')) {
            $this->line($installer->fullSql((string) ($password ?? 'CHANGE-ME-authenticator-password')));

            return self::SUCCESS;
        }

        $result = $installer->install($password !== null ? (string) $password : null);

        $this->components->twoColumnDetail('Schema api (views and RPC)', $result['schema'] ? '<fg=green>ready</>' : '<fg=red>failed</>');
        $this->components->twoColumnDetail('Roles', $result['roles'] ? '<fg=green>created</>' : ($installer->rolesExist() ? '<fg=green>present</>' : '<fg=yellow>missing</>'));
        $this->components->twoColumnDetail('Grants', $result['grants'] ? '<fg=green>applied</>' : '<fg=yellow>pending</>');

        foreach ($result['messages'] as $message) {
            $this->components->warn($message);
        }

        return $result['schema'] ? self::SUCCESS : self::FAILURE;
    }
}
