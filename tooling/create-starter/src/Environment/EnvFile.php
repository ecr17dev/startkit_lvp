<?php

namespace Starter\Installer\Environment;

/**
 * A .env file edited in place: values replace the first uncommented
 * assignment of their key (keeping comments and order) or are appended.
 */
final class EnvFile
{
    /**
     * @var list<string>
     */
    private array $lines;

    public function __construct(string $contents)
    {
        $this->lines = explode("\n", rtrim($contents, "\n"));
    }

    public static function fromFile(string $path): self
    {
        return new self(is_file($path) ? (string) file_get_contents($path) : '');
    }

    public function set(string $key, string|bool|int|null $value): self
    {
        $line = $key.'='.self::encode($value);

        foreach ($this->lines as $index => $current) {
            if (preg_match('/^'.preg_quote($key, '/').'=/', $current)) {
                $this->lines[$index] = $line;

                return $this;
            }
        }

        $this->lines[] = $line;

        return $this;
    }

    public function get(string $key): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/', $line, $matches)) {
                $value = $matches[1];

                return strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')
                    ? str_replace(['\\"', '\\$', '\\\\'], ['"', '$', '\\'], substr($value, 1, -1))
                    : $value;
            }
        }

        return null;
    }

    public function contents(): string
    {
        return implode("\n", $this->lines)."\n";
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->contents());
        @chmod($path, 0600);
    }

    public static function encode(string|bool|int|null $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        return preg_match('/^[A-Za-z0-9_.:\/@+\-,=]*$/', $value)
            ? $value
            : '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
