<?php

namespace Starter\Console\Generators;

use Illuminate\Support\Str;

/**
 * Renders the files of a module from the stubs: migration, model, factory,
 * policy, Actions, Form Request, Inertia controller and routes, Vue pages,
 * TypeScript types and navigation, API resource/controller/routes, AI
 * tools, module provider (permissions), documentation and tests.
 *
 * Paths are relative to the application (apps/platform) except the module
 * documentation, which goes to the monorepo docs/ directory.
 */
class ModuleGenerator
{
    public function __construct(protected ModuleSpec $spec, protected string $timestamp = '') {}

    /**
     * @return array<string, string> path => contents
     */
    public function files(): array
    {
        $spec = $this->spec;
        $Model = $spec->model;
        $Models = $spec->models();
        $slug = $spec->slug();
        $migration = $this->timestamp !== '' ? $this->timestamp : now()->format('Y_m_d_His');

        $files = [
            "database/migrations/{$migration}_create_{$spec->table()}_table.php" => 'migration',
            "app/Models/{$Model}.php" => 'model',
            "database/factories/{$Model}Factory.php" => 'factory',
            "app/Policies/{$Model}Policy.php" => 'policy',
            "app/Providers/Modules/{$Models}ServiceProvider.php" => 'provider',
            "app/Actions/{$Models}/Create{$Model}.php" => 'action-create',
            "app/Actions/{$Models}/Update{$Model}.php" => 'action-update',
            "app/Actions/{$Models}/Delete{$Model}.php" => 'action-delete',
            "app/Http/Requests/{$Model}Request.php" => 'request',
            "app/Http/Resources/{$Model}Resource.php" => 'resource',
            "app/Http/Controllers/{$Model}Controller.php" => 'controller',
            "routes/modules/{$slug}.web.php" => 'routes-web',
            "resources/js/pages/{$slug}/Index.vue" => 'vue-index',
            "resources/js/pages/{$slug}/Create.vue" => 'vue-create',
            "resources/js/pages/{$slug}/Edit.vue" => 'vue-edit',
            "resources/js/modules/{$slug}/{$Model}Form.vue" => 'vue-form',
            "resources/js/modules/{$slug}/types.ts" => 'ts-types',
            "resources/js/modules/{$slug}/navigation.ts" => 'ts-navigation',
            "tests/Feature/Modules/{$Models}Test.php" => 'test',
            "../../docs/modules/{$slug}.md" => 'docs',
        ];

        if ($spec->api) {
            $files["app/Http/Controllers/Api/V1/{$Model}Controller.php"] = 'api-controller';
            $files["routes/modules/{$slug}.api.php"] = 'routes-api';
        }

        if ($spec->ai) {
            foreach (['List', 'Create', 'Update', 'Delete'] as $tool) {
                $files["app/Ai/Tools/{$Models}{$tool}.php"] = 'tool-'.Str::lower($tool);
            }
        }

        $replacements = $this->replacements(array_keys($files));
        $replacements['apiTests'] = $spec->api ? $this->render('test-api', $replacements) : '';
        $replacements['aiTests'] = $spec->ai ? $this->render('test-ai', $replacements) : '';

        return array_map(fn (string $stub) => $this->render($stub, $replacements), $files);
    }

    /**
     * The tool definitions the module provider registers.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> name, class, label, kind, permission
     */
    public function tools(): array
    {
        $spec = $this->spec;
        $permission = $spec->table();
        $Models = $spec->models();

        return [
            ["{$permission}_list", "{$Models}List", "Search {$spec->plural}", 'Read', "{$permission}.read"],
            ["{$permission}_create", "{$Models}Create", "Create a {$spec->singular}", 'Write', "{$permission}.create"],
            ["{$permission}_update", "{$Models}Update", "Update a {$spec->singular}", 'Write', "{$permission}.update"],
            ["{$permission}_delete", "{$Models}Delete", "Delete a {$spec->singular}", 'Sensitive', "{$permission}.delete"],
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function render(string $stub, array $replacements): string
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2)."/stubs/module/{$stub}.stub");
        $map = [];

        foreach ($replacements as $key => $value) {
            $map['{{ '.$key.' }}'] = $value;
            $map['%%'.$key.'%%'] = $value;
        }

        return strtr($contents, $map);
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    protected function replacements(array $paths): array
    {
        $spec = $this->spec;
        $Model = $spec->model;
        $table = $spec->table();
        $title = $spec->titleField();
        $filter = $spec->filterField();
        $search = $spec->searchable() ?: [$title];
        $model = $spec->variable();
        $enums = array_values(array_filter($spec->fields, fn (Field $field) => $field->type === 'enum'));
        $lines = fn (array $items, int $indent) => implode("\n", array_map(fn (string $line) => str_repeat(' ', $indent).$line, $items));

        $searchClauses = implode('', array_map(
            fn (Field $field, int $index) => '->'.($index === 0 ? 'whereLike' : 'orWhereLike')."('{$field->name}', \"%{\$search}%\")",
            $search,
            array_keys($search),
        ));

        $replacements = [
            'Model' => $Model,
            'Models' => $spec->models(),
            'model' => $model,
            'models' => Str::camel($spec->models()),
            'table' => $table,
            'slug' => $spec->slug(),
            'permission' => $table,
            'label' => $spec->singular,
            'labels' => $spec->plural,
            'Label' => Str::ucfirst($spec->singular),
            'Labels' => Str::ucfirst($spec->plural),
            'icon' => $spec->icon,
            'titleField' => $title->name,
            'titleLabel' => $title->label(),
            'titleSample' => in_array($title->type, ['string', 'text'], true) ? "'Renamed {$spec->singular}'" : $title->sample(),
            'searchLabel' => implode(', ', array_map(fn (Field $field) => Str::lower($field->label()), $search)),
            'searchClauses' => $searchClauses,
            'specPath' => "docs/modules/{$spec->slug()}.json",

            // Backend...
            'columns' => $lines(array_map(fn (Field $field) => $field->column(), $spec->fields), 12),
            'properties' => implode("\n", array_map(fn (Field $field) => " * @property {$field->phpType()} \${$field->name}", $spec->fields)),
            'constants' => $enums === [] ? '' : "\n".implode("\n", array_map(fn (Field $field) => "    /**\n     * Values of {$field->name}.\n     *\n     * @var list<string>\n     */\n    public const {$field->constant()} = ['".implode("', '", $field->options)."'];\n", $enums)),
            'fillable' => $lines(array_map(fn (Field $field) => "'{$field->name}',", $spec->fields), 8),
            'casts' => $lines(array_values(array_filter(array_map(fn (Field $field) => $field->cast(), $spec->fields))), 12),
            'factory' => $lines(array_map(fn (Field $field) => $field->factory(), $spec->fields), 12),
            'rules' => $lines(array_map(fn (Field $field) => $field->rules($table, $Model), $spec->fields), 12),
            'resource' => $lines(array_map(fn (Field $field) => $field->resource(), $spec->fields), 12),
            'present' => $lines(array_map(fn (Field $field) => str_replace('$this->', "\${$model}->", $field->resource()), $spec->fields), 12),
            'filterRule' => $filter === null ? '' : "            '{$filter->name}' => ['nullable', Rule::in({$Model}::{$filter->constant()})],\n",
            'filterClause' => $filter === null ? '' : "            ->when(\$filters['{$filter->name}'] ?? null, fn (\$query, string \$value) => \$query->where('{$filter->name}', \$value))\n",
            'optionEntries' => $lines(array_map(fn (Field $field) => "'{$field->name}' => \$options({$Model}::{$field->constant()}),", $enums), 12),

            // Module provider...
            'providerImports' => $this->providerImports(),
            'toolsNote' => $spec->ai ? ' and AI tools (same Actions as the panel)' : '',
            'toolRegistration' => $spec->ai ? $this->toolRegistration() : '',
            'toolSchema' => $lines(array_map(fn (Field $field) => $field->toolSchema(), $spec->fields), 12),
            'toolSchemaUpdate' => $lines(array_map(fn (Field $field) => $field->toolSchema(true), $spec->fields), 12),

            // Tests...
            'sampleData' => $lines(array_map(fn (Field $field) => "'{$field->name}' => {$field->sample()},", $spec->fields), 12),
            'testImports' => implode('', array_map(fn (string $import) => "use {$import};\n", array_filter([
                $spec->api ? 'Laravel\\Sanctum\\Sanctum' : null,
                $spec->ai ? 'Starter\\Ai\\Agents\\ToolRegistry' : null,
                $spec->ai ? 'Tests\\Concerns\\CreatesAiCatalog' : null,
            ]))),
            'testTraits' => $spec->ai ? 'CreatesAiCatalog, ' : '',
            'testsNote' => ($spec->api ? ', API' : '').($spec->ai ? ', AI tools' : ''),

            'ruleImport' => $filter === null ? '' : "use Illuminate\\Validation\\Rule;\n",
            'actionRuleImport' => array_filter($spec->fields, fn (Field $field) => $field->type === 'enum' || $field->unique) === [] ? '' : "use Illuminate\\Validation\\Rule;\n",

            // Frontend...
            'badgeImport' => array_filter($spec->columns(), fn (Field $field) => in_array($field->type, ['enum', 'boolean'], true)) === [] ? '' : "import { Badge } from '@starter/ui/components/badge';\n",
            'optionLabelFn' => $enums === [] ? '' : "const optionLabel = (field: keyof {$Model}Options, value: string) =>\n    props.options[field]?.find((option) => option.value === value)?.label ??\n    value;\n\n",
            'selectImport' => $filter === null ? '' : "import {\n    Select,\n    SelectContent,\n    SelectItem,\n    SelectTrigger,\n    SelectValue,\n} from '@starter/ui/components/select';\n",
            'filterProp' => $filter === null ? '' : "; {$filter->name}?: string",
            'filterState' => $filter === null ? '' : "    {$filter->name}: props.filters.{$filter->name} ?? 'all',\n",
            'filterQuery' => $filter === null ? '' : "                {$filter->name}:\n                    filters.{$filter->name} === 'all' ? undefined : filters.{$filter->name},\n",
            'filterControl' => $filter === null ? '' : $this->filterControl($filter),
            'headers' => implode('', array_map(fn (Field $field) => "                        <TableHead class=\"hidden md:table-cell\">{{ t('".addslashes($field->label())."') }}</TableHead>\n", $spec->columns())),
            'cells' => implode('', array_map(fn (Field $field) => "                        <TableCell class=\"hidden md:table-cell\">\n                            {$field->cell($model)}\n                        </TableCell>\n", $spec->columns())),
            'colspan' => (string) (count($spec->columns()) + 3),
            'formDefaults' => implode('', array_map(fn (Field $field) => "    {$field->name}: {$field->formDefault()},\n", $spec->fields)),
            'formValues' => implode('', array_map(fn (Field $field) => "    {$field->name}: {$field->formValue('props.'.$model)},\n", $spec->fields)),
            'formImports' => $this->formImports(),
            'controls' => implode("\n", array_map(fn (Field $field) => $field->control(), $spec->fields)),
            'tsFields' => implode('', array_map(fn (Field $field) => "    {$field->name}: {$field->tsType()};\n", $spec->fields)),
            'tsFormFields' => implode('', array_map(fn (Field $field) => "    {$field->name}: {$field->formType()};\n", $spec->fields)),
            'tsOptions' => implode('', array_map(fn (Field $field) => "    {$field->name}: { value: string; label: string }[];\n", $enums)),

            // Documentation...
            'fieldRows' => implode("\n", array_map(fn (Field $field) => "| `{$field->name}` | {$field->type}".($field->options ? ' ('.implode(', ', $field->options).')' : '').' | '.($field->nullable || $field->type === 'boolean' ? 'no' : 'sí').' | '.implode(', ', array_filter([$field->unique ? 'único' : null, $field->max ? "máx. {$field->max}" : null, $field->labelEs ?? $field->label()])).' |', $spec->fields)),
            'fileList' => implode("\n", array_map(fn (string $path) => '- `'.(str_starts_with($path, '../../') ? substr($path, 6) : 'apps/platform/'.preg_replace('#migrations/\d{4}_\d{2}_\d{2}_\d{6}_#', 'migrations/*_', $path)).'`', $paths)),
            'LabelsDoc' => Str::ucfirst($spec->pluralEs ?? $spec->plural),
            'apiDocs' => $spec->api ? "- API: `GET|POST /api/v1/{$spec->slug()}`, `GET|PATCH|DELETE /api/v1/{$spec->slug()}/{id}` (`api.v1.{$spec->slug()}.*`; habilidades `read`/`write`). Documentadas en OpenAPI (`php artisan starter:api-docs`).\n" : '',
            'aiDocs' => $spec->ai ? "\n## Herramientas IA\n\n".implode("\n", array_map(fn (array $tool) => "- `{$tool[0]}` ({$tool[3]}, requiere `{$tool[4]}`)", $this->tools()))."\n\nSe ofrecen solo si el agente las habilita y el usuario tiene el permiso; las escrituras pueden pedir confirmación.\n" : '',
        ];

        return $replacements;
    }

    protected function providerImports(): string
    {
        $imports = ['Illuminate\\Support\\ServiceProvider', 'Starter\\Identity\\Permissions'];

        if ($this->spec->ai) {
            array_push($imports, 'Starter\\Ai\\Agents\\ToolDefinition', 'Starter\\Ai\\Agents\\ToolKind', 'Starter\\Ai\\Agents\\ToolRegistry');

            foreach ($this->tools() as $tool) {
                $imports[] = "App\\Ai\\Tools\\{$tool[1]}";
            }
        }

        sort($imports);

        return implode('', array_map(fn (string $import) => "use {$import};\n", $imports));
    }

    protected function toolRegistration(): string
    {
        $lines = ["\n        \$this->callAfterResolving(ToolRegistry::class, function (ToolRegistry \$registry) {"];

        foreach ($this->tools() as [$name, $class, $label, $kind, $permission]) {
            $lines[] = "            \$registry->register(new ToolDefinition('{$name}', {$class}::class, '{$this->spec->table()}', '{$label}', ToolKind::{$kind}, '{$permission}'));";
        }

        $lines[] = "        });\n";

        return implode("\n", $lines);
    }

    protected function filterControl(Field $filter): string
    {
        $model = $this->spec->variable();

        return <<<VUE
                    <Select v-model="filters.{$filter->name}">
                        <SelectTrigger class="sm:w-48" :aria-label="t('{$filter->label()}')">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{{ t('All') }}</SelectItem>
                            <SelectItem
                                v-for="option in options.{$filter->name}"
                                :key="option.value"
                                :value="option.value"
                                >{{ option.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>

        VUE;
    }

    protected function formImports(): string
    {
        $types = array_map(fn (Field $field) => $field->type, $this->spec->fields);
        $imports = [];

        if (array_diff($types, ['text', 'boolean', 'enum']) !== []) {
            $imports[] = "import { Input } from '@starter/ui/components/input';";
        }

        if (in_array('enum', $types, true)) {
            $imports[] = "import {\n    Select,\n    SelectContent,\n    SelectItem,\n    SelectTrigger,\n    SelectValue,\n} from '@starter/ui/components/select';";
        }

        if (in_array('boolean', $types, true)) {
            $imports[] = "import { Switch } from '@starter/ui/components/switch';";
        }

        if (in_array('text', $types, true)) {
            $imports[] = "import { Textarea } from '@starter/ui/components/textarea';";
        }

        return $imports === [] ? '' : implode("\n", $imports)."\n";
    }
}
