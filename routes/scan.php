<?php

use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScanLogController;

$app_name = env('APP_NAME', '');
Route::redirect('/', "/$app_name");

Route::prefix($app_name)->middleware(AuthMiddleware::class)->group(function () {
  
   Route::get('/scan-logs', [ScanLogController::class, 'index'])->name('scan-logs.index');

  // For the API endpoints
  Route::post('/scan-log/verify', [ScanLogController::class, 'verify']);
  Route::post('/scan-log/save', [ScanLogController::class, 'save']);
  Route::get('/scan-log/employee-recent-logs', [ScanLogController::class, 'employeeRecentLogs']);
  Route::get('/scan-log/today-logs', [ScanLogController::class, 'todayLogs']);
  Route::post('/scan-logs/match',  [ScanLogController::class, 'match'])->name('fingerprint.match');
  Route::post('/scan-logs/store',  [ScanLogController::class, 'store'])->name('attendance-log.store');
  Route::post('/fingerprint/identify', [ScanLogController::class, 'fingerprintIdentify'])->name('fingerprint.identify');

});
