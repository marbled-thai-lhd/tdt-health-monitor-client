<?php

use Illuminate\Support\Facades\Route;
use TDT\HealthMonitor\Http\Controllers\ForceCheckController;

Route::group(['prefix' => 'health-monitor'], function () {
    Route::post('/force-check', [ForceCheckController::class, 'forceCheck'])
        ->name('health-monitor.force-check');
});