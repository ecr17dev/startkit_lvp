<?php

use App\Http\Controllers\Admin\Ai\AiModelController;
use App\Http\Controllers\Admin\Ai\AiPromptController;
use App\Http\Controllers\Admin\Ai\AiProviderController;
use App\Http\Controllers\Admin\Ai\AiUsageController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\Settings\AuthSettingsController;
use App\Http\Controllers\Admin\Settings\BrandingSettingsController;
use App\Http\Controllers\Admin\Settings\GeneralSettingsController;
use App\Http\Controllers\Admin\Settings\MailSettingsController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
| Administration. Controllers authorize every action through Policies and
| the shared Actions (app/Actions); route middleware only gates sections.
*/

Route::middleware(['auth', 'verified', 'onboarded'])->prefix('admin')->name('admin.')->group(function () {
    // Users, sessions and invitations...
    Route::resource('users', UserController::class)->except('show');
    Route::patch('users/{user}/status', [UserController::class, 'status'])->name('users.status');
    Route::delete('users/{user}/sessions', [UserController::class, 'destroySessions'])->name('users.sessions.destroy-all');
    Route::delete('users/{user}/sessions/{session}', [UserController::class, 'destroySession'])->name('users.sessions.destroy');

    Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('sessions/{user}/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');

    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('invitations.store');
    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->middleware('throttle:10,1')->name('invitations.resend');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    // Roles and the permission matrix...
    Route::resource('roles', RoleController::class)->except('show');
    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
    Route::put('permissions/{role}', [PermissionController::class, 'update'])->name('permissions.update');

    // Audit trail...
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

    // System settings...
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::redirect('/', '/admin/settings/general')->name('index');

        Route::middleware('can:settings.read')->group(function () {
            Route::get('general', [GeneralSettingsController::class, 'edit'])->name('general.edit');
            Route::get('branding', [BrandingSettingsController::class, 'edit'])->name('branding.edit');
            Route::get('mail', [MailSettingsController::class, 'edit'])->name('mail.edit');
            Route::get('auth', [AuthSettingsController::class, 'edit'])->name('auth.edit');
        });

        Route::middleware('can:settings.update')->group(function () {
            Route::put('general', [GeneralSettingsController::class, 'update'])->name('general.update');
            Route::post('branding', [BrandingSettingsController::class, 'update'])->name('branding.update');
            Route::put('mail', [MailSettingsController::class, 'update'])->name('mail.update');
            Route::post('mail/test', [MailSettingsController::class, 'test'])->middleware('throttle:5,1')->name('mail.test');
            Route::put('auth', [AuthSettingsController::class, 'update'])->name('auth.update');
        });
    });

    // Artificial intelligence...
    Route::prefix('ai')->name('ai.')->group(function () {
        Route::middleware('can:ai.providers.manage')->group(function () {
            Route::resource('providers', AiProviderController::class)->except('show');
            Route::post('providers/{provider}/test', [AiProviderController::class, 'test'])->middleware('throttle:10,1')->name('providers.test');
            Route::post('providers/{provider}/models', [AiModelController::class, 'store'])->name('models.store');
            Route::put('models/{model}', [AiModelController::class, 'update'])->name('models.update');
            Route::delete('models/{model}', [AiModelController::class, 'destroy'])->name('models.destroy');
        });

        Route::middleware('can:ai.agents.manage')->group(function () {
            Route::resource('prompts', AiPromptController::class)->except('show');
        });

        Route::middleware('can:ai.usage.read')->group(function () {
            Route::get('usage', [AiUsageController::class, 'index'])->name('usage');
        });

        Route::put('settings', [AiUsageController::class, 'updateSettings'])
            ->middleware('can:ai.providers.manage')
            ->name('settings.update');
    });
});
