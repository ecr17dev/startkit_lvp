<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Actions\Knowledge\AddSourceFiles;
use App\Actions\Knowledge\DeleteKnowledge;
use App\Actions\Knowledge\SaveSource;
use App\Actions\Knowledge\SyncSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Starter\Knowledge\Enums\SourceStatus;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Source;

class SourceController extends Controller
{
    public function store(Request $request, Collection $collection, SaveSource $action): RedirectResponse
    {
        try {
            $action->handle($this->actor($request), $collection, $request->all());
        } catch (RagUnavailableException $exception) {
            throw ValidationException::withMessages(['rag' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Source created. The sync is running.')]);

        return back();
    }

    public function update(Request $request, Source $source, SaveSource $action): RedirectResponse
    {
        try {
            $action->handle($this->actor($request), $source->collection, $request->all(), $source);
        } catch (RagUnavailableException $exception) {
            throw ValidationException::withMessages(['rag' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Source saved.')]);

        return back();
    }

    public function files(Request $request, Source $source, AddSourceFiles $action): RedirectResponse
    {
        $action->handle($this->actor($request), $source, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Files added. The sync is running.')]);

        return back();
    }

    public function sync(Request $request, Source $source, SyncSource $action): RedirectResponse
    {
        try {
            $action->handle($this->actor($request), $source, ['force' => $request->boolean('force')]);
        } catch (RagUnavailableException $exception) {
            throw ValidationException::withMessages(['rag' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sync started.')]);

        return back();
    }

    public function status(Request $request, Source $source): RedirectResponse
    {
        Gate::authorize('rag.sources.manage');

        $data = $request->validate(['status' => ['required', Rule::in([SourceStatus::Active->value, SourceStatus::Paused->value])]]);

        $source->forceFill(['status' => $data['status']])->save();
        audit('rag.sources.'.($data['status'] === 'paused' ? 'paused' : 'resumed'), $source, ['name' => $source->name], $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['status'] === 'paused' ? __('Source paused.') : __('Source resumed.')]);

        return back();
    }

    public function destroy(Request $request, Source $source, DeleteKnowledge $action): RedirectResponse
    {
        $action->source($this->actor($request), $source);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The source was removed from the queries and is being purged.')]);

        return back();
    }
}
