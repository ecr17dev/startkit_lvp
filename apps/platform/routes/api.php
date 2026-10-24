<?php

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
            Route::get('prompts', [AiController::class, 'prompts'])->name('prompts');
            Route::post('chat', [AiController::class, 'chat'])->middleware('throttle:ai')->name('chat');
            Route::get('usage', [AiController::class, 'usage'])->name('usage');
        });
    });
});
