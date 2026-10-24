<?php

use Illuminate\Support\Facades\Route;
use Starter\Administration\Health\HealthController;

Route::get('health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])->middleware('throttle:60,1')->name('health.ready');
