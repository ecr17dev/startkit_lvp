<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Switch the interface language of the current visitor.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('starter.locales')))],
        ]);

        $request->session()->put('locale', $validated['locale']);
        $request->user()?->update(['locale' => $validated['locale']]);

        return back();
    }
}
