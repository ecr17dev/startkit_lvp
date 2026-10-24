<?php

namespace App\PostgRest;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only objects PostgREST publishes: the dedicated `api` schema with
 * read only views (security barrier) and an RPC, filtered by the claims of
 * the signed JWT with the same rules as the panel (Access):
 *
 * - api.knowledge_collections: active collections the principals may see;
 * - api.knowledge_documents: indexed documents the principals may read
 *   (rag.documents.read, collection and effective document access lists);
 * - api.whoami(): the identity PostgREST received.
 *
 * Helpers live in `api_private` (not exposed). The anonymous role gets
 * nothing, the user role only SELECT/EXECUTE on these objects: users,
 * sessions, OTP, keys, budgets and jobs are never reachable, and there are
 * no writes (changes go through Laravel, its Policies and the audit).
 */
class SchemaInstaller
{
    public const OBJECTS = ['knowledge_collections', 'knowledge_documents', 'whoami'];

    public function db(): ConnectionInterface
    {
        return DB::connection();
    }

    /**
     * Schema, helpers and views (the application role owns them).
     */
    public function schemaSql(?string $tables = null): string
    {
        $t = $this->ident($tables ?? (string) $this->db()->scalar('SELECT current_schema()'));

        return <<<SQL
CREATE SCHEMA IF NOT EXISTS api;
CREATE SCHEMA IF NOT EXISTS api_private;
REVOKE ALL ON SCHEMA api FROM PUBLIC;
REVOKE ALL ON SCHEMA api_private FROM PUBLIC;
CREATE OR REPLACE FUNCTION api_private.claims() RETURNS jsonb LANGUAGE sql STABLE AS \$\$
    SELECT coalesce(nullif(current_setting('request.jwt.claims', true), '')::jsonb, '{}'::jsonb)
\$\$;
CREATE OR REPLACE FUNCTION api_private.principals() RETURNS text[] LANGUAGE sql STABLE AS \$\$
    SELECT coalesce(array(SELECT jsonb_array_elements_text(CASE WHEN jsonb_typeof(api_private.claims() -> 'principals') = 'array' THEN api_private.claims() -> 'principals' ELSE '[]'::jsonb END)), '{}'::text[])
\$\$;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA api_private FROM PUBLIC;
CREATE OR REPLACE VIEW api.knowledge_collections WITH (security_barrier) AS
    SELECT c.id, c.slug, c.name, c.description, c.language,
        (SELECT count(*) FROM {$t}.rag_documents d WHERE d.collection_id = c.id AND d.deleted_at IS NULL AND d.status = 'indexed') AS documents,
        c.updated_at
    FROM {$t}.rag_collections c
    WHERE c.status = 'active'
        AND ('permission:rag.collections.manage' = ANY (api_private.principals())
            OR ('permission:rag.collections.read' = ANY (api_private.principals()) AND c.acl ?| api_private.principals()));
CREATE OR REPLACE VIEW api.knowledge_documents WITH (security_barrier) AS
    SELECT d.id, d.collection_id, d.title, d.uri, d.mime_type, d.size, d.chunk_count, d.indexed_at, d.updated_at
    FROM {$t}.rag_documents d
    JOIN {$t}.rag_collections c ON c.id = d.collection_id
    JOIN {$t}.rag_sources s ON s.id = d.source_id
    WHERE d.deleted_at IS NULL AND d.status = 'indexed' AND c.status = 'active'
        AND 'permission:rag.documents.read' = ANY (api_private.principals())
        AND c.acl ?| api_private.principals()
        AND coalesce(nullif(d.acl, '[]'::jsonb), nullif(s.acl, '[]'::jsonb), c.acl) ?| api_private.principals();
CREATE OR REPLACE FUNCTION api.whoami() RETURNS jsonb LANGUAGE sql STABLE AS \$\$
    SELECT jsonb_build_object('user_id', api_private.claims() ->> 'sub', 'principals', to_jsonb(api_private.principals()), 'role', current_user)
\$\$;
REVOKE EXECUTE ON FUNCTION api.whoami() FROM PUBLIC;
SQL;
    }

    /**
     * Roles (requires CREATEROLE: otherwise the DBA runs this SQL).
     */
    public function rolesSql(string $password): string
    {
        [$authenticator, $anon, $user] = $this->roles();
        $quoted = "'".str_replace("'", "''", $password)."'";

        return <<<SQL
DO \$\$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$anon}') THEN CREATE ROLE {$this->ident($anon)} NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$user}') THEN CREATE ROLE {$this->ident($user)} NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$authenticator}') THEN CREATE ROLE {$this->ident($authenticator)} LOGIN NOINHERIT NOCREATEDB NOCREATEROLE PASSWORD {$quoted}; END IF;
END \$\$;
GRANT {$this->ident($anon)} TO {$this->ident($authenticator)};
GRANT {$this->ident($user)} TO {$this->ident($authenticator)};
SQL;
    }

    /**
     * Grants of the published objects (the anonymous role gets none).
     */
    public function grantsSql(): string
    {
        [, $anon, $user] = $this->roles();
        $u = $this->ident($user);

        return <<<SQL
REVOKE ALL ON ALL TABLES IN SCHEMA api FROM {$this->ident($anon)};
GRANT USAGE ON SCHEMA api TO {$u};
GRANT USAGE ON SCHEMA api_private TO {$u};
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA api_private TO {$u};
GRANT SELECT ON api.knowledge_collections, api.knowledge_documents TO {$u};
GRANT EXECUTE ON FUNCTION api.whoami() TO {$u};
NOTIFY pgrst, 'reload schema';
SQL;
    }

    /**
     * Everything, for a database administrator.
     */
    public function fullSql(string $password): string
    {
        return implode("\n", [$this->schemaSql(), $this->rolesSql($password), $this->grantsSql()])."\n";
    }

    /**
     * Apply the schema and, when possible, the roles and grants.
     *
     * @return array{schema: bool, roles: bool, grants: bool, messages: list<string>}
     */
    public function install(?string $password = null): array
    {
        $result = ['schema' => false, 'roles' => false, 'grants' => false, 'messages' => []];

        try {
            $this->run($this->schemaSql());
            $result['schema'] = true;
        } catch (Throwable $exception) {
            $result['messages'][] = 'schema: '.$this->sanitize($exception);

            return $result;
        }

        if ($password !== null) {
            try {
                $this->run($this->rolesSql($password));
                $result['roles'] = true;
            } catch (Throwable $exception) {
                $result['messages'][] = 'roles: '.$this->sanitize($exception);
            }
        }

        if ($this->rolesExist()) {
            try {
                $this->run($this->grantsSql());
                $result['grants'] = true;
            } catch (Throwable $exception) {
                $result['messages'][] = 'grants: '.$this->sanitize($exception);
            }
        } else {
            $result['messages'][] = 'roles: missing; run the SQL of `starter:postgrest-install --sql` as an administrator.';
        }

        return $result;
    }

    /**
     * Installed state: schema objects, roles and what each role may use.
     *
     * @return array{schema: bool, objects: list<array{name: string, type: string}>, roles: array<string, bool>, user_can_select: bool, anon_can_select: bool, can_create_roles: bool}
     */
    public function status(): array
    {
        $db = $this->db();
        [$authenticator, $anon, $user] = $this->roles();
        $schema = (bool) $db->scalar("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'api')");
        $objects = $schema ? array_values(array_map(fn (object $row) => ['name' => (string) ((array) $row)['name'], 'type' => (string) ((array) $row)['type']], $db->select(
            "SELECT c.relname AS name, 'view' AS type FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'api' AND c.relkind IN ('v', 'r', 'm')
             UNION ALL SELECT p.proname, 'function' FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'api' ORDER BY 1"
        ))) : [];
        $roles = [];

        foreach ([$authenticator, $anon, $user] as $role) {
            $roles[$role] = (bool) $db->scalar('SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = ?)', [$role]);
        }

        $privilege = fn (string $role) => $schema && $roles[$role] && (bool) $db->scalar("SELECT has_table_privilege(?, 'api.knowledge_collections', 'SELECT')", [$role]);

        return [
            'schema' => $schema,
            'objects' => $objects,
            'roles' => $roles,
            'user_can_select' => $privilege($user),
            'anon_can_select' => $privilege($anon),
            'can_create_roles' => (bool) $db->scalar('SELECT rolcreaterole OR rolsuper FROM pg_roles WHERE rolname = current_user'),
        ];
    }

    public function rolesExist(): bool
    {
        [$authenticator, $anon, $user] = $this->roles();

        return (int) $this->db()->scalar('SELECT count(*) FROM pg_roles WHERE rolname IN (?, ?, ?)', [$authenticator, $anon, $user]) === 3;
    }

    /**
     * @return array{0: string, 1: string, 2: string} authenticator, anon, user
     */
    public function roles(): array
    {
        $roles = (array) config('starter.postgrest.roles');

        return array_map(function (string $key) use ($roles) {
            $name = (string) ($roles[$key] ?? '');

            if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name)) {
                throw new \InvalidArgumentException("Invalid PostgREST role name for {$key}.");
            }

            return $name;
        }, ['authenticator', 'anon', 'user']);
    }

    /**
     * Several statements (functions and DO blocks included) in one call.
     */
    protected function run(string $sql): void
    {
        DB::getPdo()->exec($sql);
    }

    protected function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    protected function sanitize(Throwable $exception): string
    {
        return mb_substr(trim((string) preg_replace('/\(Connection: .*$/s', '', $exception->getMessage())), 0, 300);
    }
}
