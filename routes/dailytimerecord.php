<?php

use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DailyTimeRecordController;

use App\Http\Controllers\BiometricStatusController;

$app_name = env('APP_NAME', '');

Route::prefix($app_name)->middleware(AuthMiddleware::class)->group(function () {
  
Route::get('/daily-time-record', [DailyTimeRecordController::class, 'index'])->name('daily-time-record.index');
Route::get('/biometric-status',        [BiometricStatusController::class, 'index'])  ->name('biometric-status.index');
    Route::post('/biometric-status/toggle', [BiometricStatusController::class, 'toggle'])->name('biometric-status.toggle');
    Route::get('/daily-time-record', [DailyTimeRecordController::class, 'index'])->name('dtr.index');
Route::get('/daily-time-record/rows', [DailyTimeRecordController::class, 'getDtrRows'])->name('dtr.rows');

});
