<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Actions\Knowledge\ControlJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Starter\Knowledge\Models\RagJob;

class JobController extends Controller
{
    public function cancel(Request $request, RagJob $job, ControlJob $action): RedirectResponse
    {
        $action->cancel($this->actor($request), $job);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cancellation requested.')]);

        return back();
    }

    public function retry(Request $request, RagJob $job, ControlJob $action): RedirectResponse
    {
        $action->retry($this->actor($request), $job);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job queued again.')]);

        return back();
    }

    public function resume(Request $request, RagJob $job, ControlJob $action): RedirectResponse
    {
        $action->resume($this->actor($request), $job, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Budget confirmed. The ingestion continues.')]);

        return back();
    }
}
