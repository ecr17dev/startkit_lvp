<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Actions\Mail\DeleteMailAutomation;
use App\Actions\Mail\SaveMailAutomation;
use App\Http\Controllers\Controller;
use App\Mail\Automation\MailAutomations;
use App\Models\MailAutomation;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Starter\Administration\Mail\MailTemplates;
use Starter\Administration\Models\MailTemplate;

class MailAutomationController extends Controller
{
    public function __construct(protected MailTemplates $templates) {}

    public function index(): Response
    {
        $names = $this->templateNames();

        $automations = MailAutomation::query()
            ->withCount([
                'deliveries as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'deliveries as pending_count' => fn ($query) => $query->where('status', 'pending'),
                'deliveries as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (MailAutomation $automation) => [
                ...$this->present($automation),
                'template_name' => $names[$automation->template_key] ?? $automation->template_key,
                'last_run_at' => $automation->last_run_at?->toIso8601String(),
                'counts' => [
                    'sent' => $automation->getAttribute('sent_count'),
                    'pending' => $automation->getAttribute('pending_count'),
                    'failed' => $automation->getAttribute('failed_count'),
                ],
            ]);

        return Inertia::render('admin/mail/automations/Index', [
            'automations' => $automations->all(),
            'events' => $this->events(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/mail/automations/Edit', [
            'automation' => null,
            ...$this->options(),
        ]);
    }

    public function store(Request $request, SaveMailAutomation $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, null, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation saved.')]);

        return to_route('admin.mail.automations.index');
    }

    public function edit(MailAutomation $automation): Response
    {
        return Inertia::render('admin/mail/automations/Edit', [
            'automation' => $this->present($automation),
            ...$this->options(),
        ]);
    }

    public function update(Request $request, MailAutomation $automation, SaveMailAutomation $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $automation, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation saved.')]);

        return to_route('admin.mail.automations.index');
    }

    /**
     * Pause or resume an automation (same validation as saving it).
     */
    public function toggle(Request $request, MailAutomation $automation, SaveMailAutomation $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $automation, [
            ...$automation->only(['name', 'trigger', 'template_key', 'settings']),
            'is_active' => ! $automation->is_active,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $automation->is_active ? __('Automation activated.') : __('Automation paused.')]);

        return back();
    }

    public function destroy(Request $request, MailAutomation $automation, DeleteMailAutomation $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $automation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation deleted.')]);

        return to_route('admin.mail.automations.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(MailAutomation $automation): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'trigger' => $automation->trigger,
            'settings' => (object) $automation->settings,
            'template_key' => $automation->template_key,
            'is_active' => $automation->is_active,
        ];
    }

    /**
     * Choices of the automation form.
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        $names = $this->templateNames();
        $categories = collect($this->templates->definitions())->map(fn ($definition) => $definition->category);

        return [
            'templates' => collect($names)
                ->map(fn (string $name, string $key) => ['key' => $key, 'name' => $name, 'category' => $categories[$key] ?? 'custom'])
                ->values()
                ->all(),
            'roles' => Role::query()->orderBy('name')->pluck('name')->all(),
            'events' => $this->events(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'timezone' => (string) (settings('general.timezone') ?: config('app.timezone')),
        ];
    }

    /**
     * Names of every template (system and custom) by key.
     *
     * @return array<string, string>
     */
    protected function templateNames(): array
    {
        $system = collect($this->templates->definitions())->map(fn ($definition) => __($definition->name))->all();
        $custom = MailTemplate::query()
            ->whereNotIn('key', array_keys($system))
            ->orderBy('name')
            ->get(['key', 'name'])
            ->mapWithKeys(fn (MailTemplate $template) => [$template->key => (string) ($template->name ?: $template->key)])
            ->all();

        return [...$system, ...$custom];
    }

    /**
     * @return list<array{key: string, label: string|array<mixed>, person: string|null}>
     */
    protected function events(): array
    {
        return array_map(
            fn (string $key, array $event) => ['key' => $key, 'label' => __($event['label']), 'person' => $event['person']],
            array_keys(MailAutomations::EVENTS),
            MailAutomations::EVENTS,
        );
    }
}
