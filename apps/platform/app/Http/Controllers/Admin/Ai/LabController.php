<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Actions\Ai\RunLab;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LabPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Ai\Lab\LabRunner;
use Starter\Ai\Models\LabRun;

/**
 * Lab: the same prompts against several models with a maximum cost and
 * concurrency. Requires ai.models.manage.
 */
class LabController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/ai/lab/Index', [
            'runs' => LabRun::query()->latest()->limit(30)->get()->map(fn (LabRun $run) => LabPresenter::run($run)),
            'models' => LabPresenter::models(),
        ]);
    }

    public function store(Request $request, RunLab $action): RedirectResponse
    {
        $request->validate(RunLab::rules());

        $run = $action->handle($this->actor($request), $request->all());

        return to_route('admin.ai.lab.show', $run);
    }

    public function show(LabRun $run): Response
    {
        return Inertia::render('admin/ai/lab/Show', ['run' => LabPresenter::run($run, true)]);
    }

    public function cancel(Request $request, LabRun $run, LabRunner $runner): RedirectResponse
    {
        $runner->cancel($run, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Run cancelled.')]);

        return back();
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
