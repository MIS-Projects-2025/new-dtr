<?php

use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

$app_name = env('APP_NAME', '');
Route::redirect('/', "/$app_name");

Route::prefix($app_name)->middleware(AuthMiddleware::class)->group(function () {
  
    Route::get("/", [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/work-schedule', [DashboardController::class, 'getWorkSchedule']);
    Route::get('/dashboard/shift-logs', [DashboardController::class, 'getShiftLogs']);
    Route::get('/dashboard/attendance-counter', [DashboardController::class, 'getAttendanceCounter']);
    Route::get('/dashboard/management-presence', [DashboardController::class, 'getManagementPresence']);
    Route::get('/dashboard/employees/filtered', [DashboardController::class, 'getFilteredEmployees']);
    Route::get('/dashboard/dtr-rows', [DashboardController::class, 'getDtrRows']);
    Route::get('/dashboard/shift-counts', [DashboardController::class, 'getShiftCounts']); // Fixed: removed duplicate /dashboard
});