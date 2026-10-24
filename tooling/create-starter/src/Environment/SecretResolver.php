<?php

namespace Starter\Installer\Environment;

/**
 * Resolves secret references (env:NAME, file:/path) from the environment
 * of the installer or private files. Values are only written to the
 * project .env (mode 0600), never printed nor exported.
 */
class SecretResolver
{
    /**
     * @param  (callable(string): (string|false|null))|null  $env
     */
    public function __construct(protected $env = null) {}

    public function resolve(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        if (str_starts_with($reference, 'env:')) {
            $name = substr($reference, 4);
            $value = $this->env !== null ? ($this->env)($name) : ($_ENV[$name] ?? $_SERVER[$name] ?? getenv($name));

            return is_string($value) && $value !== '' ? $value : null;
        }

        if (str_starts_with($reference, 'file:')) {
            $path = substr($reference, 5);

            return is_file($path) && is_readable($path) ? (trim((string) file_get_contents($path)) ?: null) : null;
        }

        return null;
    }
}
