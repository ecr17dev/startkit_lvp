<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Actions\Ai\RecommendModels;
use App\Actions\Ai\SaveModel;
use App\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LabPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Ai\Enums\Capability;

/**
 * Model recommendations for a use case. Requires ai.models.manage.
 */
class RecommenderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/ai/Recommender', [
            'models' => LabPresenter::models(),
            'defaultEvaluatorId' => AiService::defaultModel()?->id,
            'capabilities' => array_map(fn (Capability $capability) => $capability->value, Capability::cases()),
            'functions' => SaveModel::FUNCTIONS,
        ]);
    }

    public function store(Request $request, RecommendModels $action): RedirectResponse
    {
        $request->validate(RecommendModels::rules());

        /** @var User $user */
        $user = $request->user();

        Inertia::flash('recommendation', $action->handle($user, $request->all()));

        return back();
    }
}
