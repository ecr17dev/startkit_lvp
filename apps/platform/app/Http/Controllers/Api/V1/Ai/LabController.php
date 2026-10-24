<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Actions\Ai\RecommendModels;
use App\Actions\Ai\RunLab;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LabPresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Starter\Ai\Lab\LabRunner;
use Starter\Ai\Models\LabRun;

/**
 * Model recommendations and lab runs. Requires `ai.models.manage`.
 */
#[Group('AI catalog', weight: 4)]
class LabController extends Controller
{
    /**
     * Recommend models for a use case.
     *
     * Candidates are filtered by verifiable requirements and ranked
     * deterministically; an evaluator model, when given, compares only those
     * candidates and unknown ids are discarded. Its cost is recorded.
     */
    public function recommend(Request $request, RecommendModels $action): JsonResponse
    {
        $request->validate(RecommendModels::rules());

        return response()->json(['data' => $action->handle($this->actor($request), $request->all())]);
    }

    /**
     * List the latest lab runs.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->actor($request)->can('ai.models.manage'), 403);

        return response()->json(['data' => LabRun::query()->latest()->limit(50)->get()->map(fn (LabRun $run) => LabPresenter::run($run))->values()]);
    }

    /**
     * Start a lab run. Tasks run in the queue; poll the run to follow them.
     */
    public function store(Request $request, RunLab $action): JsonResponse
    {
        $request->validate(RunLab::rules());

        return response()->json(['data' => LabPresenter::run($action->handle($this->actor($request), $request->all()))], 202);
    }

    /**
     * Show a run with every result (output, error, latency, time to first
     * token, tokens and cost).
     */
    public function show(Request $request, LabRun $run): JsonResponse
    {
        abort_unless($this->actor($request)->can('ai.models.manage'), 403);

        return response()->json(['data' => LabPresenter::run($run, true)]);
    }

    /**
     * Cancel the pending tasks of a run.
     */
    public function cancel(Request $request, LabRun $run, LabRunner $runner): JsonResponse
    {
        abort_unless($this->actor($request)->can('ai.models.manage'), 403);

        $runner->cancel($run, $this->actor($request));

        return response()->json(['data' => LabPresenter::run($run->refresh())]);
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
