<?php

use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScanLogController;

$app_name = env('APP_NAME', '');
Route::redirect('/', "/$app_name");

Route::prefix($app_name)->middleware(AuthMiddleware::class)->group(function () {
  
  Route::get('/scan-logs.index',         [ScanLogController::class, 'index'])->name('scan-logs.index');
  Route::post('/scan-logs/verify', [ScanLogController::class, 'verifyAndLog'])->name('scan-logs.verify');
  Route::get('/scan-logs/recent',  [ScanLogController::class, 'getRecentLogs'])->name('scan-logs.recent');

});
