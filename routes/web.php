<?php

use App\Http\Controllers\Ai\ChatController;
use App\Http\Controllers\Auth\LoginCodeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('locale', LocaleController::class)->name('locale.update');

// Passwordless login: the user receives a one-time code by email...
Route::middleware('guest')->group(function () {
    Route::post('login/code', [LoginCodeController::class, 'store'])
        ->middleware('throttle:login-code')
        ->name('login-code.store');

    Route::get('login/code', [LoginCodeController::class, 'show'])->name('login-code.show');

    Route::post('login/code/verify', [LoginCodeController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('login-code.verify');
});

Route::middleware(['auth', 'verified'])->group(function () {
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
