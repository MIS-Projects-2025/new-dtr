<?php

use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PerfectAttendanceController;


$app_name = env('APP_NAME', '');
Route::redirect('/', "/$app_name");

Route::prefix($app_name)->middleware(AuthMiddleware::class)->group(function () {
  
Route::get('/perfect-attendance',                   [PerfectAttendanceController::class, 'index'])->name('perfect-attendance.index');
Route::get('/perfect-attendance/employees',         [PerfectAttendanceController::class, 'getEmployees']);
Route::get('/perfect-attendance/dtr-rows',          [PerfectAttendanceController::class, 'getDtrRows']);
Route::get('/perfect-attendance/stats',             [PerfectAttendanceController::class, 'getPerfectAttendanceStats'])->name('perfect-attendance.stats');
Route::get('/perfect-attendance/filter-options',    [PerfectAttendanceController::class, 'getFilterOptions']);

});
