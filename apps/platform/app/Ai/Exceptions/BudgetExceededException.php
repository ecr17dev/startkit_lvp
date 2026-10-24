<?php

namespace App\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class BudgetExceededException extends RuntimeException
{
    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 429);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back();
    }
}
