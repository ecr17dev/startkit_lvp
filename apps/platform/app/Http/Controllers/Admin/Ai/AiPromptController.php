<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Ai\Models\AiModel;

class AiPromptController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/ai/prompts/Index', [
            'prompts' => AiPrompt::query()
                ->with('model.connection')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (AiPrompt $prompt) => [
                    ...$prompt->only(['id', 'name', 'slug', 'description', 'is_default', 'is_active', 'updated_at']),
                    'model' => $prompt->model?->displayName(),
                    'provider' => $prompt->model?->connection->label,
                    'excerpt' => Str::limit($prompt->content, 160),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/ai/prompts/Create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $prompt = AiPrompt::query()->create($this->validated($request));
        $this->ensureSingleDefault($prompt);

        audit('ai.prompts.created', $prompt, ['slug' => $prompt->slug]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prompt created.')]);

        return to_route('admin.ai.prompts.index');
    }

    public function edit(AiPrompt $prompt): Response
    {
        return Inertia::render('admin/ai/prompts/Edit', [
            ...$this->formData(),
            'prompt' => $prompt->only(['id', 'name', 'slug', 'description', 'content', 'ai_model_id', 'temperature', 'max_tokens', 'is_default', 'is_active']),
        ]);
    }

    public function update(Request $request, AiPrompt $prompt): RedirectResponse
    {
        $prompt->update($this->validated($request, $prompt));
        $this->ensureSingleDefault($prompt);

        audit('ai.prompts.updated', $prompt, ['changed' => array_keys($prompt->getChanges())]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prompt updated.')]);

        return to_route('admin.ai.prompts.index');
    }

    public function destroy(AiPrompt $prompt): RedirectResponse
    {
        $prompt->delete();

        audit('ai.prompts.deleted', $prompt, ['slug' => $prompt->slug]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prompt deleted.')]);

        return to_route('admin.ai.prompts.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'models' => AiModel::query()->with('connection')->usable()->forFunction('text')->orderBy('connection_id')->orderBy('model')->get()
                ->map(fn (AiModel $model) => [
                    'id' => $model->id,
                    'label' => $model->displayName(),
                    'connection' => $model->connection->label,
                ]),
            'variables' => ['app_name', 'user_name', 'user_email', 'locale', 'date', 'datetime'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?AiPrompt $prompt = null): array
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('ai_prompts', 'slug')->ignore($prompt?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
            'ai_model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    protected function ensureSingleDefault(AiPrompt $prompt): void
    {
        if ($prompt->is_default) {
            AiPrompt::query()->whereKeyNot($prompt->getKey())->update(['is_default' => false]);
        }
    }
}
