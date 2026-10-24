<?php

namespace App\Actions\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Ai\Lab\LabRunner;
use Starter\Ai\Models\LabRun;

class RunLab
{
    public function __construct(protected LabRunner $runner) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            /** Prompts to send to every model (1 to 10). */
            'prompts' => ['required', 'array', 'min:1', 'max:10'],
            'prompts.*' => ['required', 'string', 'max:4000'],
            'system_prompt' => ['nullable', 'string', 'max:8000'],
            /** Catalog models to compare (1 to 6). */
            'model_ids' => ['required', 'array', 'min:1', 'max:6'],
            'model_ids.*' => ['integer', 'distinct', Rule::exists('ai_models', 'id')->where('is_enabled', true)],
            'max_tokens' => ['nullable', 'integer', 'between:16,8000'],
            /** Maximum cost in USD for the whole run. */
            'max_cost' => ['required', 'numeric', 'min:0', 'max:1000'],
            'max_concurrency' => ['required', 'integer', 'between:1,4'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input): LabRun
    {
        Gate::forUser($actor)->authorize('ai.models.manage');

        /** @var array{name: string, prompts: array<int, string>, system_prompt?: string|null, model_ids: array<int, int|string>, max_tokens?: int|null, max_cost: string|float|int, max_concurrency: int} $data */
        $data = Validator::make($input, self::rules())->validate();

        $data['prompts'] = array_values($data['prompts']);
        $data['model_ids'] = array_values(array_map('intval', $data['model_ids']));

        return $this->runner->create($data, $actor);
    }
}
