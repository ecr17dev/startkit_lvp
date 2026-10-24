<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AiModelController extends Controller
{
    public function store(Request $request, AiProvider $provider): RedirectResponse
    {
        $provider->models()->create($this->validated($request, $provider));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model added.')]);

        return back();
    }

    public function update(Request $request, AiModel $model): RedirectResponse
    {
        $model->update($this->validated($request, $model->provider, $model));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model updated.')]);

        return back();
    }

    public function destroy(AiModel $model): RedirectResponse
    {
        $model->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model deleted.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, AiProvider $provider, ?AiModel $model = null): array
    {
        return $request->validate([
            'model' => [
                'required', 'string', 'max:255',
                Rule::unique('ai_models', 'model')->where('ai_provider_id', $provider->id)->ignore($model?->id),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'input_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'cache_read_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'cache_write_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'context_window' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ]);
    }
}
