<?php

use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AuthController;
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
    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:login')->name('auth.token');
    Route::post('auth/code', [AuthController::class, 'requestCode'])->middleware('throttle:login-code')->name('auth.code');
    Route::post('auth/code/verify', [AuthController::class, 'verifyCode'])->middleware('throttle:10,1')->name('auth.code.verify');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->middleware('ability:read')->name('me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('users', [UserController::class, 'index'])->middleware('ability:read')->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('ability:read')->name('users.show');
        Route::post('users', [UserController::class, 'store'])->middleware('ability:write')->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('ability:write')->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('ability:write')->name('users.destroy');

        Route::get('roles', [RoleController::class, 'index'])->middleware('ability:read')->name('roles.index');

        Route::middleware(['ability:ai', 'can:ai.chat'])->prefix('ai')->name('ai.')->group(function () {
            Route::get('prompts', [AiController::class, 'prompts'])->name('prompts');
            Route::post('chat', [AiController::class, 'chat'])->middleware('throttle:ai')->name('chat');
            Route::get('usage', [AiController::class, 'usage'])->name('usage');
        });
    });
});
