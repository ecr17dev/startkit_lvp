<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Actions\Mail\CreateMailTemplate;
use App\Actions\Mail\DeleteMailTemplate;
use App\Actions\Mail\GenerateMailTemplate;
use App\Actions\Mail\SaveMailTemplate;
use App\Actions\Mail\SendTemplateTest;
use App\Http\Controllers\Controller;
use App\Models\MailAutomation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Administration\Mail\MailTemplates;
use Starter\Administration\Models\MailTemplate;

class MailTemplateController extends Controller
{
    public function __construct(protected MailTemplates $templates) {}

    public function index(): Response
    {
        $stored = MailTemplate::query()->get(['key', 'locale', 'name', 'updated_at'])->groupBy('key');

        $system = collect($this->templates->definitions())->map(fn ($definition) => [
            'key' => $definition->key,
            'category' => $definition->category,
            'name' => __($definition->name),
            'description' => __($definition->description),
            'customized' => $stored->get($definition->key)?->pluck('locale')->values()->all() ?? [],
            'custom' => false,
        ]);

        $custom = $stored->filter(fn ($rows, string $key) => $this->templates->definition($key) === null)
            ->map(fn ($rows, string $key) => [
                'key' => $key,
                'category' => 'custom',
                'name' => (string) ($rows->first()->name ?? $key),
                'description' => '',
                'customized' => $rows->pluck('locale')->values()->all(),
                'custom' => true,
            ]);

        return Inertia::render('admin/mail/templates/Index', [
            'templates' => $system->values()->merge($custom->values())->all(),
            'locales' => config('starter.locales'),
        ]);
    }

    public function edit(Request $request, string $key): Response
    {
        $locales = array_keys((array) config('starter.locales'));
        $locale = in_array($request->query('locale'), $locales, true) ? (string) $request->query('locale') : app()->getLocale();
        $source = $this->templates->source($key, $locale);
        abort_if($source === null, 404);

        $definition = $this->templates->definition($key);

        return Inertia::render('admin/mail/templates/Edit', [
            'template' => [
                'key' => $key,
                'name' => $definition ? __($definition->name) : ($source['name'] ?? $key),
                'description' => $definition ? __($definition->description) : '',
                'custom' => $definition === null,
                'customized' => $source['customized'],
                'locale' => $locale,
                'subject' => $source['subject'],
                'body' => $source['body'],
                'format' => $source['format'],
            ],
            'variables' => collect($this->templates->variables($key))
                ->map(fn (array $variable, string $name) => ['name' => $name, 'description' => __($variable['description']), 'sample' => $variable['sample']])
                ->values()
                ->all(),
            'locales' => config('starter.locales'),
            'usedBy' => MailAutomation::query()->where('template_key', $key)->get(['id', 'name'])->all(),
        ]);
    }

    public function store(Request $request, CreateMailTemplate $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $template = $action->handle($actor, $request->all());

        return to_route('admin.mail.templates.edit', ['key' => $template->key, 'locale' => $template->locale]);
    }

    public function update(Request $request, string $key, SaveMailTemplate $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $template = $action->handle($actor, $key, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return to_route('admin.mail.templates.edit', ['key' => $key, 'locale' => $template->locale]);
    }

    public function destroy(Request $request, string $key, DeleteMailTemplate $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $locale = $request->string('locale')->toString() ?: null;
        $action->handle($actor, $key, $locale);

        if ($this->templates->definition($key) !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Default template restored.')]);

            return to_route('admin.mail.templates.edit', ['key' => $key, 'locale' => $locale]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template deleted.')]);

        return to_route('admin.mail.templates.index');
    }

    /**
     * Live preview of the editor content with the sample values (JSON).
     */
    public function preview(Request $request, string $key): JsonResponse
    {
        abort_unless($this->templates->exists($key), 404);

        $data = Validator::make($request->all(), [
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'format' => ['required', 'in:'.implode(',', MailTemplates::FORMATS)],
        ])->validate();

        $body = (string) ($data['body'] ?? '');
        $rendered = $this->templates->preview($key, (string) ($data['subject'] ?? ''), $data['format'] === 'html' ? SaveMailTemplate::cleanHtml($body) : $body, $data['format']);

        return response()->json([
            'subject' => $rendered->subject,
            'html' => $rendered->html,
            'text' => $rendered->text,
            'unknown' => $this->templates->unknownVariables($key, ($data['subject'] ?? '').' '.$body),
        ]);
    }

    /**
     * Draft with AI from a description (JSON, nothing is saved).
     */
    public function generate(Request $request, string $key, GenerateMailTemplate $action): JsonResponse
    {
        abort_unless($this->templates->exists($key), 404);

        /** @var User $actor */
        $actor = $request->user();

        return response()->json($action->handle($actor, $key, $request->all()));
    }

    /**
     * Send the editor content to oneself.
     */
    public function test(Request $request, string $key, SendTemplateTest $action): RedirectResponse
    {
        abort_unless($this->templates->exists($key), 404);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $key, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test email sent to :email.', ['email' => $actor->email])]);

        return back();
    }
}
