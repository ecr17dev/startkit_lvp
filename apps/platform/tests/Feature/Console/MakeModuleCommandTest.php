<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Starter\Console\Commands\MakeModuleCommand;
use Starter\Console\Generators\Field;
use Starter\Console\Generators\ModuleGenerator;
use Starter\Console\Generators\ModuleSpec;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The module generator writes into a sandbox copy of the application
 * (bootstrap/providers.php, lang/es.json), never into the real one. The
 * Projects module of the repository is the end to end proof: it was
 * generated with this command and has its own tests.
 */
class MakeModuleCommandTest extends TestCase
{
    protected string $root;

    protected string $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = base_path();
        $this->root = storage_path('framework/testing/module-'.bin2hex(random_bytes(4)));
        $app = "{$this->root}/apps/platform";

        File::ensureDirectoryExists("{$app}/bootstrap");
        File::ensureDirectoryExists("{$app}/lang");
        File::ensureDirectoryExists("{$app}/database/migrations");
        File::copy(base_path('bootstrap/providers.php'), "{$app}/bootstrap/providers.php");
        File::put("{$app}/lang/es.json", json_encode(['Title' => 'Título existente'], JSON_PRETTY_PRINT)."\n");

        $this->app->setBasePath($app);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->original);
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function make(array $options): int
    {
        return Artisan::call('starter:make-module', ['--no-format' => true, ...$options]);
    }

    public function test_fields_are_parsed_and_validated(): void
    {
        $field = Field::parse('budget:decimal=10,3:nullable:label=Amount:label_es=Monto');
        $this->assertSame(['decimal', 10, 3, true, 'Amount', 'Monto'], [$field->type, $field->precision, $field->scale, $field->nullable, $field->label(), $field->labelEs]);

        $enum = Field::parse('status:enum=draft|done:options_es=Borrador|Hecho');
        $this->assertSame(['draft' => 'Draft', 'done' => 'Done'], $enum->optionLabels());
        $this->assertSame("'status' => ['required', Rule::in(Task::STATUS_OPTIONS)],", $enum->rules('tasks', 'Task'));
        $this->assertSame("\$table->string('code', 20)->unique();", Field::parse('code:string:max=20:unique')->column());

        foreach (['id:string', 'Name:string', 'name:color', 'name', 'state:enum=', 'name:string:shiny'] as $invalid) {
            try {
                Field::parse($invalid);
                $this->fail("{$invalid} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new ModuleSpec('User', [Field::parse('title:string')]);
    }

    public function test_dry_run_lists_the_files_and_writes_nothing(): void
    {
        $code = $this->make(['name' => 'Task', '--field' => ['title:string:max=120', 'notes:text:nullable'], '--dry-run' => true, '--json' => true]);
        $result = json_decode(Artisan::output(), true);

        $this->assertSame(0, $code);
        $this->assertTrue($result['dry_run']);
        $this->assertContains('apps/platform/app/Actions/Tasks/CreateTask.php', $result['files']);
        $this->assertContains('apps/platform/routes/modules/tasks.api.php', $result['files']);
        $this->assertNotContains('apps/platform/app/Ai/Tools/TasksList.php', $result['files']);
        $this->assertDirectoryDoesNotExist(base_path('app'));
    }

    public function test_it_generates_a_complete_module_in_the_application(): void
    {
        $code = $this->make([
            'name' => 'Ticket',
            '--field' => ['subject:string:max=150:label_es=Asunto', 'priority:enum=low|normal|high:options_es=Baja|Normal|Alta', 'due_at:datetime:nullable', 'is_urgent:boolean', 'title:string:nullable'],
            '--ai' => true,
            '--label-es' => 'ticket/tickets',
            '--icon' => 'Ticket',
        ]);

        $this->assertSame(0, $code, Artisan::output());

        foreach ([
            'database/factories/TicketFactory.php', 'app/Models/Ticket.php', 'app/Policies/TicketPolicy.php',
            'app/Providers/Modules/TicketsServiceProvider.php', 'app/Actions/Tickets/UpdateTicket.php',
            'app/Http/Requests/TicketRequest.php', 'app/Http/Controllers/TicketController.php',
            'app/Http/Controllers/Api/V1/TicketController.php', 'app/Http/Resources/TicketResource.php',
            'routes/modules/tickets.web.php', 'routes/modules/tickets.api.php', 'app/Ai/Tools/TicketsDelete.php',
            'resources/js/pages/tickets/Index.vue', 'resources/js/modules/tickets/TicketForm.vue',
            'resources/js/modules/tickets/types.ts', 'resources/js/modules/tickets/navigation.ts',
            'tests/Feature/Modules/TicketsTest.php',
        ] as $path) {
            $this->assertFileExists(base_path($path));
        }

        $this->assertFileExists("{$this->root}/docs/modules/tickets.md");
        $this->assertSame('Ticket', json_decode((string) file_get_contents("{$this->root}/docs/modules/tickets.json"), true)['name']);
        $this->assertCount(1, glob(base_path('database/migrations/*_create_tickets_table.php')) ?: []);

        // Every generated PHP file is valid PHP...
        foreach (File::allFiles(base_path()) as $file) {
            if ($file->getExtension() === 'php') {
                $lint = new Process([PHP_BINARY, '-l', $file->getPathname()]);
                $lint->run();
                $this->assertTrue($lint->isSuccessful(), $file->getPathname().': '.$lint->getOutput());
            }
        }

        $model = (string) file_get_contents(base_path('app/Models/Ticket.php'));
        $this->assertStringContainsString("public const PRIORITY_OPTIONS = ['low', 'normal', 'high'];", $model);
        $this->assertStringContainsString("'due_at' => 'datetime',", $model);
        $this->assertStringContainsString("'tickets_delete', TicketsDelete::class, 'tickets', 'Delete a ticket', ToolKind::Sensitive, 'tickets.delete'", (string) file_get_contents(base_path('app/Providers/Modules/TicketsServiceProvider.php')));
        $this->assertStringContainsString('satisfies ModuleNavItem', (string) file_get_contents(base_path('resources/js/modules/tickets/navigation.ts')));
        $this->assertStringContainsString("priority: 'low' | 'normal' | 'high';", (string) file_get_contents(base_path('resources/js/modules/tickets/types.ts')));

        // The provider is registered once, after the existing ones...
        $providers = (string) file_get_contents(base_path('bootstrap/providers.php'));
        $this->assertStringContainsString('use App\\Providers\\Modules\\TicketsServiceProvider;', $providers);
        $this->assertStringContainsString('// Modules generated with starter:make-module...', $providers);
        $this->assertStringEndsWith("    TicketsServiceProvider::class,\n];\n", $providers);
        $this->assertStringContainsString('// Starter modules (packages/php/*), registered explicitly...', $providers);

        // Spanish translations are added without overwriting existing keys...
        $es = (array) json_decode((string) file_get_contents(base_path('lang/es.json')), true);
        $this->assertSame('Título existente', $es['Title']);
        $this->assertSame('Nuevo ticket', $es['New ticket']);
        $this->assertSame('Ticket eliminado.', $es['Ticket deleted.']);
        $this->assertSame('Asunto', $es['Subject']);
        $this->assertSame('Alta', $es['High']);
        $this->assertSame('Ver tickets', $es['View tickets']);

        // Without --force nothing is overwritten (stable exit code)...
        $this->assertSame(MakeModuleCommand::CONFLICT, $this->make(['name' => 'Ticket', '--field' => ['subject:string']]));

        // With --force the same migration is reused and the provider is not duplicated...
        $this->assertSame(0, $this->make(['name' => 'Ticket', '--field' => ['subject:string'], '--force' => true, '--no-api' => true]));
        $this->assertCount(1, glob(base_path('database/migrations/*_create_tickets_table.php')) ?: []);
        $this->assertSame(1, substr_count((string) file_get_contents(base_path('bootstrap/providers.php')), 'TicketsServiceProvider::class'));
    }

    public function test_invalid_input_fails_with_a_stable_code(): void
    {
        $this->assertSame(MakeModuleCommand::INVALID, $this->make(['name' => 'Task']));
        $this->assertSame(MakeModuleCommand::INVALID, $this->make(['name' => 'Task', '--field' => ['title:colour']]));
        $this->assertSame(MakeModuleCommand::INVALID, $this->make(['--spec' => '/does/not/exist.json']));
        $this->assertSame(MakeModuleCommand::INVALID, $this->make(['name' => 'Task', '--field' => ['title:string'], '--gender-es' => 'x', '--json' => true]));
        $this->assertSame(MakeModuleCommand::INVALID, json_decode(Artisan::output(), true)['code']);
    }

    public function test_the_projects_module_matches_its_specification(): void
    {
        $this->app->setBasePath($this->original);
        $spec = ModuleSpec::fromArray((array) json_decode((string) file_get_contents(base_path('../../docs/modules/projects.json')), true));
        $files = (new ModuleGenerator($spec))->files();

        // The committed module is the generator output (formatted): same files...
        foreach (array_keys($files) as $path) {
            $existing = str_contains($path, 'database/migrations/') ? glob(base_path('database/migrations/*_create_projects_table.php')) : [base_path($path)];
            $this->assertNotEmpty($existing);
            $this->assertFileExists((string) $existing[0]);
        }

        $this->assertSame('projects', $spec->table());
        $this->assertTrue($spec->ai && $spec->api);
    }
}
