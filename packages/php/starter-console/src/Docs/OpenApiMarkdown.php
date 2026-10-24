<?php

namespace Starter\Console\Docs;

/**
 * Markdown reference of an OpenAPI document: one section per tag with
 * each operation, its permission notes, parameters, request fields and
 * responses. The output is deterministic (no dates), so CI can compare it
 * with the committed file.
 */
class OpenApiMarkdown
{
    protected const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @var array<string, mixed>
     */
    protected array $document = [];

    /**
     * @param  array<string, mixed>  $document
     */
    public function render(array $document): string
    {
        $this->document = $document;
        $info = (array) ($document['info'] ?? []);
        $server = $document['servers'][0]['url'] ?? '/api';
        $lines = [
            '# Referencia de la API',
            '',
            '<!-- Generado por `php artisan starter:api-docs` desde tooling/contracts/openapi.json. No editar a mano. -->',
            '',
            '**'.($info['title'] ?? 'API').'** · versión '.($info['version'] ?? '1.0.0').' · base `'.$server.'`',
            '',
            trim((string) ($info['description'] ?? '')),
            '',
            'El contrato completo (para clientes y validación) está en `tooling/contracts/openapi.json` y el cliente tipado en `@starter/api-client`. Con la aplicación en marcha, `/docs/api` muestra la documentación interactiva.',
            '',
        ];

        $groups = $this->operationsByTag();
        $lines[] = '## Índice';
        $lines[] = '';

        foreach (array_keys($groups) as $tag) {
            $lines[] = '- ['.$tag.'](#'.$this->anchor($tag).') ('.count($groups[$tag]).')';
        }

        $lines[] = '';

        foreach ($groups as $tag => $operations) {
            $lines[] = '## '.$tag;
            $lines[] = '';
            $lines[] = '| Método | Ruta | Resumen |';
            $lines[] = '| --- | --- | --- |';

            foreach ($operations as [$method, $path, $operation]) {
                $lines[] = '| `'.strtoupper($method).'` | `'.$path.'` | '.$this->cell((string) ($operation['summary'] ?? '')).' |';
            }

            $lines[] = '';

            foreach ($operations as [$method, $path, $operation]) {
                array_push($lines, ...$this->operation($method, $path, $operation));
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @return array<string, list<array{0: string, 1: string, 2: array<string, mixed>}>>
     */
    protected function operationsByTag(): array
    {
        $groups = [];

        foreach ((array) ($this->document['tags'] ?? []) as $tag) {
            $groups[(string) $tag['name']] = [];
        }

        foreach ((array) ($this->document['paths'] ?? []) as $path => $operations) {
            foreach (self::METHODS as $method) {
                if (! isset($operations[$method]) || ! is_array($operations[$method])) {
                    continue;
                }

                $tag = (string) ($operations[$method]['tags'][0] ?? 'Other');
                $groups[$tag][] = [$method, (string) $path, $operations[$method]];
            }
        }

        return array_filter($groups);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    protected function operation(string $method, string $path, array $operation): array
    {
        $lines = ['### '.strtoupper($method).' '.$path, ''];

        if (! empty($operation['summary'])) {
            $lines[] = '**'.$operation['summary'].'**';
            $lines[] = '';
        }

        if (! empty($operation['description'])) {
            $lines[] = trim((string) preg_replace('/\n(?!\n)/', ' ', (string) $operation['description']));
            $lines[] = '';
        }

        $security = $operation['security'] ?? $this->document['security'] ?? [];
        $lines[] = $security === [] ? '_Sin autenticación._' : '_Requiere `Authorization: Bearer <token>`._';
        $lines[] = '';

        $parameters = array_values(array_filter((array) ($operation['parameters'] ?? []), 'is_array'));

        if ($parameters !== []) {
            $lines[] = '| Parámetro | En | Tipo | Requerido | Descripción |';
            $lines[] = '| --- | --- | --- | --- | --- |';

            foreach ($parameters as $parameter) {
                $lines[] = '| `'.$parameter['name'].'` | '.$parameter['in'].' | '.$this->type((array) ($parameter['schema'] ?? [])).' | '.(! empty($parameter['required']) ? 'sí' : 'no').' | '.$this->cell((string) ($parameter['description'] ?? '')).' |';
            }

            $lines[] = '';
        }

        $body = $operation['requestBody']['content'] ?? [];
        $schema = $this->resolve((array) (($body['application/json'] ?? $body['multipart/form-data'] ?? [])['schema'] ?? []));

        if (! empty($schema['properties'])) {
            $required = (array) ($schema['required'] ?? []);
            $lines[] = 'Cuerpo'.(isset($body['multipart/form-data']) ? ' (`multipart/form-data`)' : ' (JSON)').':';
            $lines[] = '';
            $lines[] = '| Campo | Tipo | Requerido | Descripción |';
            $lines[] = '| --- | --- | --- | --- |';

            foreach ((array) $schema['properties'] as $name => $property) {
                $lines[] = '| `'.$name.'` | '.$this->type((array) $property).' | '.(in_array($name, $required, true) ? 'sí' : 'no').' | '.$this->cell((string) ($property['description'] ?? '')).' |';
            }

            $lines[] = '';
        }

        $responses = [];

        foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
            $response = $this->resolve((array) $response);
            $responses[] = '`'.$status.'`'.(! empty($response['description']) ? ' '.$this->cell((string) $response['description']) : '');
        }

        if ($responses !== []) {
            $lines[] = 'Respuestas: '.implode(' · ', $responses).'.';
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function type(array $schema): string
    {
        $schema = $this->resolve($schema);

        if (isset($schema['enum'])) {
            return implode(' \| ', array_map(fn ($value) => $value === null ? 'null' : '`'.$value.'`', (array) $schema['enum']));
        }

        $type = implode(' \| ', (array) ($schema['type'] ?? 'object'));

        if (str_contains($type, 'array') && isset($schema['items'])) {
            $type = str_replace('array', $this->type((array) $schema['items']).'[]', $type);
        }

        return isset($schema['format']) ? "{$type} ({$schema['format']})" : $type;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    protected function resolve(array $node): array
    {
        $depth = 0;

        while (isset($node['$ref']) && is_string($node['$ref']) && $depth++ < 10) {
            $target = $this->document;

            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $target = is_array($target) ? ($target[$segment] ?? []) : [];
            }

            $node = is_array($target) ? $target : [];
        }

        return $node;
    }

    protected function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], trim($text));
    }

    protected function anchor(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9áéíóúñü]+/u', '-', mb_strtolower($text)), '-');
    }
}
