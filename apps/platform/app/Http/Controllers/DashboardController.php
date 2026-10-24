<?php

namespace App\Http\Controllers;

use App\Ai\AiService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\Connection;

class DashboardController extends Controller
{
    public function __construct(protected AiService $ai) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $stats = [];

        if ($user->can('users.read')) {
            $stats['users'] = [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
                'new_this_month' => User::query()->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
            ];
        }

        if ($user->can('roles.read')) {
            $stats['roles'] = Role::query()->count();
        }

        if ($user->can('ai.usage.read')) {
            $stats['ai'] = [
                'month_cost' => $this->ai->monthlySpend(),
                'month_calls' => AiRequest::query()->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
                'month_unknown_cost' => AiRequest::query()->where('created_at', '>=', Carbon::now()->startOfMonth())->where('cost_status', CostStatus::Unknown->value)->count(),
                'providers' => Connection::query()->active()->count(),
            ];
        }

        if ($user->can('ai.chat')) {
            $stats['my_ai'] = [
                'month_cost' => $this->ai->monthlySpend($user),
                'month_calls' => AiRequest::query()->where('user_id', $user->getKey())->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
            ];
        }

        return Inertia::render('Dashboard', [
            'stats' => (object) $stats,
            'lastLoginAt' => $user->last_login_at,
        ]);
    }
}
