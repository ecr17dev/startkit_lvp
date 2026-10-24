<?php

use App\Http\Controllers\Ai\TranscriptionController;
use App\Http\Controllers\Api\V1\ActionProposalController;
use App\Http\Controllers\Api\V1\Ai\AgentController as AiAgentController;
use App\Http\Controllers\Api\V1\Ai\BudgetController as AiBudgetController;
use App\Http\Controllers\Api\V1\Ai\ConnectionController as AiConnectionController;
use App\Http\Controllers\Api\V1\Ai\ModelController as AiModelController;
use App\Http\Controllers\Api\V1\Ai\UsageController as AiUsageController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Token based API secured with Laravel Sanctum. The OpenAPI (Swagger)
| documentation is generated automatically by Scramble at /docs/api.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/otp', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('auth.otp');
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify')->name('auth.otp.verify');
    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:login')->name('auth.token');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->middleware('ability:read')->name('me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::middleware('ability:read')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
            Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
            Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        });

        Route::middleware('ability:write')->group(function () {
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::patch('users/{user}/status', [UserController::class, 'status'])->name('users.status');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

            Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('invitations.store');
            Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->middleware('throttle:10,1')->name('invitations.resend');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
        });

        Route::middleware(['ability:ai', 'can:ai.chat'])->prefix('ai')->name('ai.')->group(function () {
            Route::get('chat/agents', [AiController::class, 'agents'])->name('chat.agents');
            Route::post('chat', [AiController::class, 'chat'])->middleware('throttle:ai')->name('chat');
            Route::get('me/usage', [AiController::class, 'usage'])->name('me.usage');
            Route::post('transcriptions', TranscriptionController::class)->middleware('throttle:ai')->name('transcriptions.store');
        });

        // Changes prepared by agent tools, confirmed or cancelled by their actor...
        Route::middleware(['ability:ai', 'can:ai.chat'])->prefix('admin/action-proposals')->name('admin.action-proposals.')->group(function () {
            Route::get('/', [ActionProposalController::class, 'index'])->name('index');
            Route::get('{proposal}', [ActionProposalController::class, 'show'])->whereUuid('proposal')->name('show');
            Route::post('{proposal}/confirm', [ActionProposalController::class, 'confirm'])->whereUuid('proposal')->middleware('throttle:ai')->name('confirm');
            Route::post('{proposal}/reject', [ActionProposalController::class, 'reject'])->whereUuid('proposal')->middleware('throttle:ai')->name('reject');
        });

        // AI administration: same Actions and permissions as the panel...
        Route::prefix('ai')->name('ai.')->group(function () {
            Route::middleware('ability:read')->group(function () {
                Route::get('connections', [AiConnectionController::class, 'index'])->name('connections.index');
                Route::get('connections/{connection}', [AiConnectionController::class, 'show'])->name('connections.show');
                Route::get('models', [AiModelController::class, 'index'])->name('models.index');
                Route::get('models/{model}', [AiModelController::class, 'show'])->name('models.show');
                Route::get('models/{model}/prices', [AiModelController::class, 'prices'])->name('models.prices.index');
                Route::get('usage', [AiUsageController::class, 'summary'])->name('usage.summary');
                Route::get('usage/requests', [AiUsageController::class, 'requests'])->name('usage.requests');
                Route::get('budgets', [AiBudgetController::class, 'index'])->name('budgets.index');
                Route::get('agents', [AiAgentController::class, 'index'])->name('agents.index');
                Route::get('agents/{agent}', [AiAgentController::class, 'show'])->name('agents.show');
            });

            Route::middleware('ability:write')->group(function () {
                Route::post('connections', [AiConnectionController::class, 'store'])->name('connections.store');
                Route::patch('connections/{connection}', [AiConnectionController::class, 'update'])->name('connections.update');
                Route::delete('connections/{connection}', [AiConnectionController::class, 'destroy'])->name('connections.destroy');
                Route::post('connections/{connection}/test', [AiConnectionController::class, 'test'])->middleware('throttle:10,1')->name('connections.test');
                Route::post('connections/{connection}/discover', [AiConnectionController::class, 'discover'])->middleware('throttle:10,1')->name('connections.discover');
                Route::post('models', [AiModelController::class, 'store'])->name('models.store');
                Route::patch('models/{model}', [AiModelController::class, 'update'])->name('models.update');
                Route::post('models/{model}/verify', [AiModelController::class, 'verify'])->middleware('throttle:20,1')->name('models.verify');
                Route::post('models/{model}/prices', [AiModelController::class, 'setPrice'])->name('models.prices.store');
                Route::post('budgets', [AiBudgetController::class, 'store'])->name('budgets.store');
                Route::patch('budgets/{budget}', [AiBudgetController::class, 'update'])->name('budgets.update');
                Route::delete('budgets/{budget}', [AiBudgetController::class, 'destroy'])->name('budgets.destroy');
                Route::post('agents', [AiAgentController::class, 'store'])->name('agents.store');
                Route::patch('agents/{agent}', [AiAgentController::class, 'update'])->name('agents.update');
                Route::put('agents/{agent}/draft', [AiAgentController::class, 'saveDraft'])->name('agents.draft.update');
                Route::post('agents/{agent}/publish', [AiAgentController::class, 'publish'])->name('agents.publish');
                Route::post('agents/{agent}/versions/{version}/restore', [AiAgentController::class, 'restore'])->scopeBindings()->name('agents.versions.restore');
                Route::post('agents/{agent}/test', [AiAgentController::class, 'test'])->middleware('throttle:20,1')->name('agents.test');
            });
        });
    });
});
