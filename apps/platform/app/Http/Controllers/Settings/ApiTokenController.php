<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    /**
     * Abilities that can be granted to personal access tokens.
     *
     * @var list<string>
     */
    public const ABILITIES = ['read', 'write', 'ai'];

    /**
     * Show the user's API tokens.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/ApiTokens', [
            'tokens' => $user->tokens()->latest()->get()->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ]),
            'abilities' => self::ABILITIES,
            'plainTextToken' => $request->session()->get('plainTextToken'),
            'docsUrl' => $user->can('viewApiDocs') ? url('docs/api') : null,
        ]);
    }

    /**
     * Create a new API token.
     */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['array', 'min:1'],
            'abilities.*' => [Rule::in(self::ABILITIES)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $token = $user->createToken(
            $validated['name'],
            $validated['abilities'] ?? self::ABILITIES,
            isset($validated['expires_in_days']) ? now()->addDays((int) $validated['expires_in_days']) : null,
        );

        audit('api_tokens.created', $user, ['name' => $validated['name'], 'abilities' => $token->accessToken->abilities]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created. Copy it now, it will not be shown again.')]);

        return back()->with('plainTextToken', $token->plainTextToken);
    }

    /**
     * Revoke the given API token.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->tokens()->whereKey($token)->delete() > 0) {
            audit('api_tokens.revoked', $user, ['token_id' => $token]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return back();
    }
}
