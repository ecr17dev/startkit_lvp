<?php

use App\Http\Controllers\Admin\Ai\AiModelController;
use App\Http\Controllers\Admin\Ai\AiPromptController;
use App\Http\Controllers\Admin\Ai\AiProviderController;
use App\Http\Controllers\Admin\Ai\AiUsageController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\Settings\AuthSettingsController;
use App\Http\Controllers\Admin\Settings\BrandingSettingsController;
use App\Http\Controllers\Admin\Settings\GeneralSettingsController;
use App\Http\Controllers\Admin\Settings\MailSettingsController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    // Users & roles (authorized through UserPolicy and RolePolicy)...
    Route::resource('users', UserController::class)->except('show');
    Route::resource('roles', RoleController::class)->except('show');

    // System settings...
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::redirect('/', '/admin/settings/general')->name('index');

        Route::middleware('can:settings.general')->group(function () {
            Route::get('general', [GeneralSettingsController::class, 'edit'])->name('general.edit');
            Route::put('general', [GeneralSettingsController::class, 'update'])->name('general.update');
        });

        Route::middleware('can:settings.branding')->group(function () {
            Route::get('branding', [BrandingSettingsController::class, 'edit'])->name('branding.edit');
            Route::post('branding', [BrandingSettingsController::class, 'update'])->name('branding.update');
        });

        Route::middleware('can:settings.mail')->group(function () {
            Route::get('mail', [MailSettingsController::class, 'edit'])->name('mail.edit');
            Route::put('mail', [MailSettingsController::class, 'update'])->name('mail.update');
            Route::post('mail/test', [MailSettingsController::class, 'test'])->middleware('throttle:5,1')->name('mail.test');
        });

        Route::middleware('can:settings.auth')->group(function () {
            Route::get('auth', [AuthSettingsController::class, 'edit'])->name('auth.edit');
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

        Route::middleware('can:ai.prompts.manage')->group(function () {
            Route::resource('prompts', AiPromptController::class)->except('show');
        });

        Route::middleware('can:ai.usage.view')->group(function () {
            Route::get('usage', [AiUsageController::class, 'index'])->name('usage');
        });

        Route::put('settings', [AiUsageController::class, 'updateSettings'])
            ->middleware('can:ai.providers.manage')
            ->name('settings.update');
    });
});
