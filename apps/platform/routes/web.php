<?php

use App\Http\Controllers\Ai\ChatController;
use App\Http\Controllers\Auth\ConfirmWithCodeController;
use App\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Http\Controllers\Auth\OneTimeCodeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('locale', LocaleController::class)->name('locale.update');

// Passwordless login, sign up and invitations with one-time codes
// (the login page itself, GET /login, is served by Fortify)...
Route::middleware('guest')->group(function () {
    Route::post('login/code', [OneTimeCodeController::class, 'store'])
        ->middleware('throttle:otp-request')
        ->name('login.code');

    Route::get('login/verify', [OneTimeCodeController::class, 'show'])->name('login.verify');

    Route::post('login/verify', [OneTimeCodeController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('login.verify.store');

    Route::post('login/verify/resend', [OneTimeCodeController::class, 'resend'])
        ->middleware('throttle:otp-request')
        ->name('login.verify.resend');

    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])
        ->middleware('throttle:otp-request')
        ->name('register.store');

    Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])
        ->middleware('throttle:otp-request')
        ->name('invitations.accept');
});

Route::middleware('auth')->group(function () {
    // Passwordless confirmation of sensitive actions (password.confirm)...
    Route::post('user/confirm-code', [ConfirmWithCodeController::class, 'store'])
        ->middleware('throttle:otp-request')
        ->name('password.confirm.code');

    Route::post('user/confirm-code/verify', [ConfirmWithCodeController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('password.confirm.code.verify');

    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
});

Route::middleware(['auth', 'verified', 'onboarded'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('can:ai.chat')->prefix('ai')->name('ai.')->group(function () {
        Route::get('chat/{conversation?}', [ChatController::class, 'index'])->name('chat');
        Route::post('chat/stream', [ChatController::class, 'stream'])->middleware('throttle:ai')->name('chat.stream');
        Route::patch('chat/{conversation}', [ChatController::class, 'update'])->name('chat.update');
        Route::delete('chat/{conversation}', [ChatController::class, 'destroy'])->name('chat.destroy');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';

// Unknown URLs go through the web middleware (session, locale, shared
// props) so the not found page is translated and knows the user...
Route::fallback(fn () => abort(404));
