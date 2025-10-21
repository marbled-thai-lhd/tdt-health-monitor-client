<?php

use Illuminate\Support\Facades\Route;
use TDT\HealthMonitor\Http\Controllers\BackupController;

Route::group(['prefix' => 'api'], function () {
    Route::post('/backup/download-url', [BackupController::class, 'generateDownloadUrl'])
        ->name('health-monitor.backup.download-url');
});