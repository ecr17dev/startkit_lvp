<?php

namespace Starter\Knowledge\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Knowledge is disabled, not active, or its connection does not support
 * the operation: 503 for API clients, a form error in the panel.
 */
class RagUnavailableException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 503);
        }

        return back()->withErrors(['rag' => $this->getMessage()]);
    }
}
