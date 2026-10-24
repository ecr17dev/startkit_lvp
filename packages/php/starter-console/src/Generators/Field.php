<?php

namespace Starter\Console\Generators;

use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * A field of a generated module and everything derived from it: column,
 * validation rules, cast, factory value, API/TS type, form control, table
 * cell and the JSON schema of the AI tools.
 */
final class Field
{
    public const TYPES = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'enum', 'email', 'url'];

    protected const RESERVED = ['id', 'created_by', 'created_at', 'updated_at', 'deleted_at', 'creator'];

    /**
     * @param  list<string>  $options  enum values
     * @param  list<string>  $optionsEs  Spanish labels of the enum values
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly bool $unique = false,
        public readonly bool $index = false,
        public readonly ?int $max = null,
        public readonly ?int $min = null,
        public readonly array $options = [],
        public readonly int $precision = 12,
        public readonly int $scale = 2,
        public readonly ?string $label = null,
        public readonly ?string $labelEs = null,
        public readonly array $optionsEs = [],
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) || in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException("Invalid or reserved field name \"{$name}\" (snake_case, not ".implode(', ', self::RESERVED).').');
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown type \"{$type}\" for {$name}; use ".implode(', ', self::TYPES).'.');
        }

        if ($type === 'enum' && ($options === [] || array_filter($options, fn ($option) => ! preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $option)) !== [])) {
            throw new InvalidArgumentException("The enum {$name} needs values of lowercase letters, digits, _ or -.");
        }

        if ($type === 'decimal' && ($precision < 1 || $precision > 30 || $scale < 0 || $scale > $precision)) {
            throw new InvalidArgumentException("Invalid decimal precision for {$name}.");
        }
    }

    /**
     * Parse "name:type[:modifier...]", e.g. "status:enum=draft|active|archived",
     * "budget:decimal=12,2:nullable", "title:string:max=150:unique:label_es=Título".
     */
    public static function parse(string $definition): self
    {
        $parts = array_map('trim', explode(':', trim($definition)));

        if (count($parts) < 2) {
            throw new InvalidArgumentException("Field \"{$definition}\" must be name:type[:modifiers].");
        }

        [$type, $argument] = array_pad(explode('=', $parts[1], 2), 2, null);
        $attributes = ['name' => $parts[0], 'type' => $type];

        if ($type === 'enum') {
            $attributes['options'] = array_values(array_filter(explode('|', (string) $argument)));
        } elseif ($type === 'decimal' && $argument !== null) {
            [$precision, $scale] = array_pad(explode(',', $argument), 2, '2');
            $attributes['precision'] = (int) $precision;
            $attributes['scale'] = (int) $scale;
        }

        foreach (array_slice($parts, 2) as $modifier) {
            [$key, $value] = array_pad(explode('=', $modifier, 2), 2, null);

            match ($key) {
                'nullable', 'unique', 'index' => $attributes[$key] = true,
                'max', 'min' => $attributes[$key] = (int) $value,
                'label' => $attributes['label'] = (string) $value,
                'label_es' => $attributes['label_es'] = (string) $value,
                'options_es' => $attributes['options_es'] = explode('|', (string) $value),
                default => throw new InvalidArgumentException("Unknown modifier \"{$modifier}\" for {$parts[0]}."),
            };
        }

        return self::fromArray($attributes);
    }

    /**
     * @param  array<string, mixed>  $field  JSON spec: {name, type, nullable, unique, index, max, min, options, precision, scale, label, label_es}
     */
    public static function fromArray(array $field): self
    {
        return new self(
            name: (string) ($field['name'] ?? ''),
            type: (string) ($field['type'] ?? ''),
            nullable: (bool) ($field['nullable'] ?? false),
            unique: (bool) ($field['unique'] ?? false),
            index: (bool) ($field['index'] ?? false),
            max: isset($field['max']) ? (int) $field['max'] : null,
            min: isset($field['min']) ? (int) $field['min'] : null,
            options: array_values(array_map('strval', (array) ($field['options'] ?? []))),
            precision: (int) ($field['precision'] ?? 12),
            scale: (int) ($field['scale'] ?? 2),
            label: isset($field['label']) ? (string) $field['label'] : null,
            labelEs: isset($field['label_es']) ? (string) $field['label_es'] : null,
            optionsEs: array_values(array_map('strval', (array) ($field['options_es'] ?? []))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable ?: null,
            'unique' => $this->unique ?: null,
            'index' => $this->index ?: null,
            'max' => $this->max,
            'min' => $this->min,
            'options' => $this->options ?: null,
            'precision' => $this->type === 'decimal' ? $this->precision : null,
            'scale' => $this->type === 'decimal' ? $this->scale : null,
            'label' => $this->label(),
            'label_es' => $this->labelEs,
            'options_es' => $this->optionsEs ?: null,
        ], fn ($value) => $value !== null);
    }

    public function label(): string
    {
        return $this->label ?? Str::ucfirst(str_replace('_', ' ', $this->name));
    }

    /**
     * English labels of the enum values (translation keys).
     *
     * @return array<string, string>
     */
    public function optionLabels(): array
    {
        return array_combine($this->options, array_map(fn (string $option) => Str::headline($option), $this->options));
    }

    public function camel(): string
    {
        return Str::camel($this->name);
    }

    public function constant(): string
    {
        return Str::upper($this->name).'_OPTIONS';
    }

    public function isText(): bool
    {
        return in_array($this->type, ['string', 'email', 'url', 'text'], true);
    }

    /**
     * Migration column definition.
     */
    public function column(): string
    {
        $column = match ($this->type) {
            'string', 'email', 'url' => "\$table->string('{$this->name}', ".($this->max ?? 255).')',
            'text' => "\$table->text('{$this->name}')",
            'integer' => "\$table->integer('{$this->name}')",
            'decimal' => "\$table->decimal('{$this->name}', {$this->precision}, {$this->scale})",
            'boolean' => "\$table->boolean('{$this->name}')->default(false)",
            'date' => "\$table->date('{$this->name}')",
            'datetime' => "\$table->timestampTz('{$this->name}')",
            'enum' => "\$table->string('{$this->name}', 50)",
            default => throw new LogicException("Unsupported type {$this->type}."),
        };

        if ($this->nullable && $this->type !== 'boolean') {
            $column .= '->nullable()';
        }

        if ($this->unique) {
            $column .= '->unique()';
        } elseif ($this->index || $this->type === 'enum') {
            $column .= '->index()';
        }

        return $column.';';
    }

    /**
     * Validation rules (PHP source), shared by the Actions and Form Requests.
     */
    public function rules(string $table, string $model): string
    {
        $rules = [$this->type === 'boolean' ? "'boolean'" : ($this->nullable ? "'nullable'" : "'required'")];

        $rules = [...$rules, ...match ($this->type) {
            'string' => ["'string'", "'max:".($this->max ?? 255)."'"],
            'text' => ["'string'", "'max:".($this->max ?? 10000)."'"],
            'email' => ["'string'", "'email:rfc'", "'max:".($this->max ?? 255)."'"],
            'url' => ["'string'", "'url:http,https'", "'max:".($this->max ?? 255)."'"],
            'integer' => ["'integer'", "'min:".($this->min ?? 0)."'", "'max:".($this->max ?? 2147483647)."'"],
            'decimal' => ["'numeric'", "'decimal:0,{$this->scale}'", "'min:".($this->min ?? 0)."'", "'max:".str_repeat('9', $this->precision - $this->scale).'\''],
            'boolean' => [],
            'date' => ["'date'"],
            'datetime' => ["'date'"],
            'enum' => ["Rule::in({$model}::{$this->constant()})"],
            default => throw new LogicException("Unsupported type {$this->type}."),
        }];

        if ($this->type !== 'integer' && $this->type !== 'decimal' && $this->min !== null) {
            $rules[] = "'min:{$this->min}'";
        }

        if ($this->unique) {
            $rules[] = "Rule::unique('{$table}', '{$this->name}')->ignore(\$record?->id)";
        }

        return "'{$this->name}' => [".implode(', ', $rules).'],';
    }

    public function cast(): ?string
    {
        return match ($this->type) {
            'integer' => "'{$this->name}' => 'integer',",
            'decimal' => "'{$this->name}' => 'decimal:{$this->scale}',",
            'boolean' => "'{$this->name}' => 'boolean',",
            'date' => "'{$this->name}' => 'date',",
            'datetime' => "'{$this->name}' => 'datetime',",
            default => null,
        };
    }

    /**
     * PHPDoc type of the model property.
     */
    public function phpType(): string
    {
        $type = match ($this->type) {
            'integer' => 'int',
            'boolean' => 'bool',
            'date', 'datetime' => 'CarbonInterface',
            default => 'string',
        };

        return $this->nullable && $this->type !== 'boolean' ? "{$type}|null" : $type;
    }

    /**
     * Value of the API resource / Inertia props.
     */
    public function resource(): string
    {
        return match ($this->type) {
            'date' => "'{$this->name}' => \$this->{$this->name}?->toDateString(),",
            'datetime' => "'{$this->name}' => \$this->{$this->name}?->toIso8601String(),",
            default => "'{$this->name}' => \$this->{$this->name},",
        };
    }

    public function tsType(): string
    {
        $type = match ($this->type) {
            'integer' => 'number',
            'boolean' => 'boolean',
            'enum' => implode(' | ', array_map(fn ($option) => "'{$option}'", $this->options)),
            default => 'string',
        };

        return $this->nullable && $this->type !== 'boolean' ? "{$type} | null" : $type;
    }

    /**
     * Initial value of the form (Create page).
     */
    public function formDefault(): string
    {
        return match ($this->type) {
            'boolean' => 'false',
            'enum' => $this->nullable ? "''" : "'{$this->options[0]}'",
            default => "''",
        };
    }

    /**
     * Form value from a record (Edit page).
     */
    public function formValue(string $record): string
    {
        return match ($this->type) {
            'boolean' => "{$record}.{$this->name}",
            'integer' => "{$record}.{$this->name} === null ? '' : String({$record}.{$this->name})",
            'datetime' => "{$record}.{$this->name}?.slice(0, 16) ?? ''",
            default => "{$record}.{$this->name} ?? ''",
        };
    }

    public function formType(): string
    {
        return match ($this->type) {
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    /**
     * Factory value (Faker).
     */
    public function factory(): string
    {
        $value = match ($this->type) {
            'string' => 'fake()->'.($this->unique ? 'unique()->' : '').($this->max !== null && $this->max < 40 ? "lexify(str_repeat('?', ".min(10, $this->max).'))' : 'sentence(3)'),
            'text' => 'fake()->paragraph()',
            'email' => 'fake()->unique()->safeEmail()',
            'url' => 'fake()->url()',
            'integer' => 'fake()->numberBetween('.($this->min ?? 0).', '.min($this->max ?? 1000, 1000).')',
            'decimal' => 'fake()->randomFloat('.$this->scale.', '.($this->min ?? 0).', 1000)',
            'boolean' => 'fake()->boolean()',
            'date' => 'fake()->dateTimeBetween(\'-1 year\', \'+1 year\')->format(\'Y-m-d\')',
            'datetime' => 'fake()->dateTimeBetween(\'-1 year\', \'+1 year\')',
            'enum' => "fake()->randomElement(['".implode("', '", $this->options)."'])",
            default => throw new LogicException("Unsupported type {$this->type}."),
        };

        return "'{$this->name}' => {$value},";
    }

    /**
     * A valid value for the generated tests (PHP source).
     */
    public function sample(): string
    {
        return match ($this->type) {
            'string' => "'".mb_substr('Sample '.str_replace('_', ' ', $this->name), 0, $this->max ?? 255)."'",
            'text' => "'A longer description.'",
            'email' => "'sample@example.com'",
            'url' => "'https://example.com'",
            'integer' => (string) max(1, $this->min ?? 0),
            'decimal' => "'".max(1, $this->min ?? 0).'.'.str_repeat('5', max(1, $this->scale))."'",
            'boolean' => 'true',
            'date' => "'2026-03-15'",
            'datetime' => "'2026-03-15T10:30'",
            'enum' => "'".($this->options[1] ?? $this->options[0])."'",
            default => throw new LogicException("Unsupported type {$this->type}."),
        };
    }

    /**
     * JSON schema of the field for the AI tools (Laravel JsonSchema).
     */
    public function toolSchema(bool $forUpdate = false): string
    {
        $schema = match ($this->type) {
            'integer' => '$schema->integer()',
            'decimal' => '$schema->number()',
            'boolean' => '$schema->boolean()',
            'enum' => "\$schema->string()->enum(['".implode("', '", $this->options)."'])",
            default => '$schema->string()',
        };

        $description = match ($this->type) {
            'date' => ' (YYYY-MM-DD)',
            'datetime' => ' (ISO 8601)',
            default => '',
        };

        $schema .= "->description('".addslashes($this->label()).$description.".')";

        if (! $forUpdate && ! $this->nullable && $this->type !== 'boolean') {
            $schema .= '->required()';
        }

        return "'{$this->name}' => {$schema},";
    }

    /**
     * Form control of the shared form component (Vue).
     */
    public function control(): string
    {
        $id = str_replace('_', '-', $this->name);
        $label = "{{ t('".addslashes($this->label())."') }}";
        $error = "<InputError :message=\"form.errors.{$this->name}\" />";

        if ($this->type === 'boolean') {
            return <<<VUE
                <div class="flex items-center gap-3">
                    <Switch id="{$id}" v-model="form.{$this->name}" />
                    <Label for="{$id}">{$label}</Label>
                    {$error}
                </div>
            VUE;
        }

        $required = $this->nullable ? '' : ' required';
        $control = match ($this->type) {
            'text' => "<Textarea id=\"{$id}\" v-model=\"form.{$this->name}\" rows=\"4\"{$required} />",
            'enum' => <<<VUE
                <Select v-model="form.{$this->name}">
                            <SelectTrigger id="{$id}" class="w-full sm:w-64">
                                <SelectValue :placeholder="t('Select')" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="option in options.{$this->name}" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                            </SelectContent>
                        </Select>
                VUE,
            'integer' => "<Input id=\"{$id}\" v-model=\"form.{$this->name}\" type=\"number\" step=\"1\"{$required} />",
            'decimal' => "<Input id=\"{$id}\" v-model=\"form.{$this->name}\" type=\"number\" step=\"0.".str_repeat('0', max(0, $this->scale - 1))."1\"{$required} />",
            'date' => "<Input id=\"{$id}\" v-model=\"form.{$this->name}\" type=\"date\" class=\"w-full sm:w-48\"{$required} />",
            'datetime' => "<Input id=\"{$id}\" v-model=\"form.{$this->name}\" type=\"datetime-local\" class=\"w-full sm:w-64\"{$required} />",
            'email' => "<Input id=\"{$id}\" v-model=\"form.{$this->name}\" type=\"email\"{$required} />",
            'url' => "<Input id=\"{$id}\" v-model=\"form.{$this->name}\" type=\"url\" placeholder=\"https://\"{$required} />",
            default => "<Input id=\"{$id}\" v-model=\"form.{$this->name}\" maxlength=\"".($this->max ?? 255)."\"{$required} />",
        };

        return <<<VUE
            <div class="grid gap-2">
                <Label for="{$id}">{$label}</Label>
                {$control}
                {$error}
            </div>
        VUE;
    }

    /**
     * Cell of the index table (Vue).
     */
    public function cell(string $record): string
    {
        return match ($this->type) {
            'boolean' => "<Badge :variant=\"{$record}.{$this->name} ? 'default' : 'outline'\">{{ {$record}.{$this->name} ? t('Yes') : t('No') }}</Badge>",
            'enum' => "<Badge v-if=\"{$record}.{$this->name}\" variant=\"secondary\">{{ optionLabel('{$this->name}', {$record}.{$this->name}) }}</Badge>",
            'date' => "{{ formatDate({$record}.{$this->name}, { dateStyle: 'medium', timeZone: 'UTC' }) }}",
            'datetime' => "{{ formatDate({$record}.{$this->name}) }}",
            'decimal', 'integer' => "<span class=\"tabular-nums\">{{ {$record}.{$this->name} ?? '—' }}</span>",
            default => "{{ {$record}.{$this->name} || '—' }}",
        };
    }
}
