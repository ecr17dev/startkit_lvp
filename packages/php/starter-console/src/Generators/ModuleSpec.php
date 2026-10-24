<?php

namespace Starter\Console\Generators;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What a module is called and contains: model, fields, optional JSON API
 * and AI tools, and the labels (English source strings plus the Spanish
 * translations written to lang/es.json).
 */
final class ModuleSpec
{
    public readonly string $model;

    public readonly string $singular;

    public readonly string $plural;

    /**
     * @param  list<Field>  $fields
     */
    public function __construct(
        string $name,
        public readonly array $fields,
        public readonly bool $api = true,
        public readonly bool $ai = false,
        ?string $singular = null,
        ?string $plural = null,
        public readonly ?string $singularEs = null,
        public readonly ?string $pluralEs = null,
        public readonly string $genderEs = 'm',
        public readonly string $icon = 'FolderKanban',
    ) {
        $this->model = Str::studly(Str::singular($name));

        if (! preg_match('/^[A-Z][A-Za-z0-9]{1,40}$/', $this->model) || in_array($this->model, ['User', 'Role', 'Permission', 'Module', 'Model', 'Request', 'Resource'], true)) {
            throw new InvalidArgumentException("Invalid or reserved module name \"{$name}\".");
        }

        if ($fields === []) {
            throw new InvalidArgumentException('A module needs at least one field.');
        }

        $names = array_map(fn (Field $field) => $field->name, $fields);

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('Field names must be unique.');
        }

        if (! in_array($genderEs, ['m', 'f'], true)) {
            throw new InvalidArgumentException('The Spanish gender must be m or f.');
        }

        if (! preg_match('/^[A-Z][A-Za-z0-9]{1,40}$/', $icon)) {
            throw new InvalidArgumentException("Invalid icon \"{$icon}\" (a lucide icon name, e.g. FolderKanban).");
        }

        $this->singular = $singular ?? Str::lower(Str::snake($this->model, ' '));
        $this->plural = $plural ?? Str::plural($this->singular);
    }

    /**
     * @param  array<string, mixed>  $spec  {name, fields: [...], api, ai, labels: {en: {singular, plural}, es: {singular, plural, gender}}, icon}
     */
    public static function fromArray(array $spec): self
    {
        return new self(
            name: (string) ($spec['name'] ?? ''),
            fields: array_map(fn ($field) => Field::fromArray((array) $field), array_values((array) ($spec['fields'] ?? []))),
            api: (bool) ($spec['api'] ?? true),
            ai: (bool) ($spec['ai'] ?? false),
            singular: $spec['labels']['en']['singular'] ?? null,
            plural: $spec['labels']['en']['plural'] ?? null,
            singularEs: $spec['labels']['es']['singular'] ?? null,
            pluralEs: $spec['labels']['es']['plural'] ?? null,
            genderEs: (string) ($spec['labels']['es']['gender'] ?? 'm'),
            icon: (string) ($spec['icon'] ?? 'FolderKanban'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->model,
            'api' => $this->api,
            'ai' => $this->ai,
            'icon' => $this->icon,
            'labels' => array_filter([
                'en' => ['singular' => $this->singular, 'plural' => $this->plural],
                'es' => $this->singularEs !== null ? ['singular' => $this->singularEs, 'plural' => $this->pluralEs ?? $this->singularEs, 'gender' => $this->genderEs] : null,
            ]),
            'fields' => array_map(fn (Field $field) => $field->toArray(), $this->fields),
        ];
    }

    public function models(): string
    {
        return Str::pluralStudly($this->model);
    }

    public function table(): string
    {
        return Str::snake($this->models());
    }

    public function slug(): string
    {
        return Str::kebab($this->models());
    }

    public function variable(): string
    {
        return Str::camel($this->model);
    }

    /**
     * Field shown as the record name (and searched): the first text field.
     */
    public function titleField(): Field
    {
        foreach ($this->fields as $field) {
            if (in_array($field->type, ['string', 'email'], true)) {
                return $field;
            }
        }

        return $this->fields[0];
    }

    /**
     * @return list<Field>
     */
    public function searchable(): array
    {
        return array_values(array_filter($this->fields, fn (Field $field) => in_array($field->type, ['string', 'email', 'url'], true)));
    }

    /**
     * Fields of the index table (besides the title).
     *
     * @return list<Field>
     */
    public function columns(): array
    {
        $title = $this->titleField()->name;

        return array_slice(array_values(array_filter($this->fields, fn (Field $field) => $field->name !== $title && $field->type !== 'text')), 0, 4);
    }

    /**
     * First enum field: the filter of the index.
     */
    public function filterField(): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->type === 'enum') {
                return $field;
            }
        }

        return null;
    }

    /**
     * English source strings of the module and their Spanish translation
     * (only when Spanish labels are given; field labels and enum values use
     * label_es/options_es).
     *
     * @return array<string, string>
     */
    public function translations(): array
    {
        $Label = Str::ucfirst($this->singular);
        $Labels = Str::ucfirst($this->plural);
        $strings = [];

        if ($this->singularEs !== null) {
            $m = $this->genderEs === 'm';
            $es = $this->singularEs;
            $esPlural = $this->pluralEs ?? $this->singularEs;
            $article = $m ? 'el' : 'la';
            $articles = $m ? 'los' : 'las';
            $a = $m ? 'un' : 'una';
            $suffix = $m ? 'o' : 'a';

            $strings = [
                $Labels => Str::ucfirst($esPlural),
                "Create and manage the {$this->plural}." => "Crea y administra {$articles} {$esPlural}.",
                "New {$this->singular}" => ($m ? 'Nuevo ' : 'Nueva ').$es,
                "Edit {$this->singular}" => "Editar {$es}",
                "Create {$this->singular}" => "Crear {$es}",
                "Delete {$this->singular}" => "Eliminar {$es}",
                "{$Label} created." => Str::ucfirst($es)." cread{$suffix}.",
                "{$Label} updated." => Str::ucfirst($es)." actualizad{$suffix}.",
                "{$Label} deleted." => Str::ucfirst($es)." eliminad{$suffix}.",
                "The {$this->singular} will be deleted. This cannot be undone." => "Se eliminará {$article} {$es}. Esta acción no se puede deshacer.",
                "No {$this->plural} found." => "No se encontraron {$esPlural}.",
                "Search {$this->plural}" => "Buscar {$esPlural}",
                "View {$this->plural}" => "Ver {$esPlural}",
                "Create {$this->plural}" => "Crear {$esPlural}",
                "Edit {$this->plural}" => "Editar {$esPlural}",
                "Delete {$this->plural}" => "Eliminar {$esPlural}",
                "Get a {$this->singular}" => "Ver {$a} {$es}",
                "Create a {$this->singular}" => "Crear {$a} {$es}",
                "Update a {$this->singular}" => "Actualizar {$a} {$es}",
                "Delete a {$this->singular}" => "Eliminar {$a} {$es}",
                "Create the {$this->singular} :name" => "Crear {$article} {$es} :name",
                "Update the {$this->singular} :name" => "Actualizar {$article} {$es} :name",
                "Delete the {$this->singular} :name" => "Eliminar {$article} {$es} :name",
            ];
        }

        foreach ($this->fields as $field) {
            if ($field->labelEs !== null) {
                $strings[$field->label()] = $field->labelEs;
            }

            foreach (array_values($field->optionLabels()) as $index => $label) {
                if (isset($field->optionsEs[$index])) {
                    $strings[$label] = $field->optionsEs[$index];
                }
            }
        }

        return $strings + [
            'Yes' => 'Sí',
            'No' => 'No',
            'Select' => 'Seleccionar',
            'Created by' => 'Creado por',
            'Last updated' => 'Última actualización',
        ];
    }
}
