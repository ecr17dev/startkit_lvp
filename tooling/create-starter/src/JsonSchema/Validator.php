<?php

namespace Starter\Installer\JsonSchema;

/**
 * Small JSON Schema (draft 2020-12) validator covering the keywords the
 * starter contracts use: type, const, enum, required, properties,
 * additionalProperties, items, pattern, minLength/maxLength,
 * minimum/maximum, format (email, uri) and local $ref ($defs). Unknown
 * keywords are ignored, so it never accepts more than the schema allows
 * for the supported ones.
 */
final class Validator
{
    /**
     * @var array<string, mixed>
     */
    private array $root = [];

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> errors as "pointer: message"
     */
    public function validate(mixed $data, array $schema): array
    {
        $this->root = $schema;
        $errors = [];
        $this->check($data, $schema, '', $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $schema = [...$this->resolve($schema['$ref']), ...array_diff_key($schema, ['$ref' => true])];
        }

        $at = $path === '' ? '/' : $path;

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$at}: must be ".json_encode($schema['const']);

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$at}: must be one of ".implode(', ', array_map(fn ($item) => (string) json_encode($item), $schema['enum']));

            return;
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];

            if (! array_filter($types, fn ($type) => $this->isType($value, (string) $type))) {
                $errors[] = "{$at}: must be of type ".implode('|', $types);

                return;
            }
        }

        if (is_string($value)) {
            $this->checkString($value, $schema, $at, $errors);
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$at}: must be >= {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$at}: must be <= {$schema['maximum']}";
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                $this->check($item, $schema['items'], "{$path}/{$index}", $errors);
            }
        }

        if (is_array($value) && ($value === [] || ! array_is_list($value)) && $this->isType($value, 'object')) {
            $this->checkObject($value, $schema, $path, $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkString(string $value, array $schema, string $at, array &$errors): void
    {
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = "{$at}: must have at least {$schema['minLength']} characters";
        }

        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = "{$at}: must have at most {$schema['maxLength']} characters";
        }

        if (isset($schema['pattern']) && ! preg_match('/'.str_replace('/', '\/', (string) $schema['pattern']).'/u', $value)) {
            $errors[] = "{$at}: does not match {$schema['pattern']}";
        }

        $format = $schema['format'] ?? null;

        if ($format === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = "{$at}: must be an email address";
        }

        if ($format === 'uri' && ! preg_match('#^[a-z][a-z0-9+.-]*://\S+$#i', $value)) {
            $errors[] = "{$at}: must be a URI";
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkObject(array $value, array $schema, string $path, array &$errors): void
    {
        foreach ((array) ($schema['required'] ?? []) as $property) {
            if (! array_key_exists((string) $property, $value)) {
                $errors[] = ($path === '' ? '/' : $path).": missing required property \"{$property}\"";
            }
        }

        $properties = (array) ($schema['properties'] ?? []);

        foreach ($value as $key => $item) {
            $pointer = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);

            if (isset($properties[$key]) && is_array($properties[$key])) {
                $this->check($item, $properties[$key], $pointer, $errors);
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$pointer}: is not allowed";
            } elseif (is_array($schema['additionalProperties'] ?? null)) {
                $this->check($item, $schema['additionalProperties'], $pointer, $errors);
            }
        }
    }

    private function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(string $ref): array
    {
        if (! str_starts_with($ref, '#/')) {
            return [];
        }

        $node = $this->root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($node) || ! isset($node[$segment])) {
                return [];
            }

            $node = $node[$segment];
        }

        return is_array($node) ? $node : [];
    }
}
