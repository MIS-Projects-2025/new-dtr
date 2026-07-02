<?php

use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttendanceSummaryController;

$app_name = env('APP_NAME', '');

Route::prefix($app_name)->middleware(AuthMiddleware::class)->group(function () {

    Route::get('attendance-summary',      [AttendanceSummaryController::class, 'index'])->name('attendance.summary');
    Route::get('attendance-summary/data', [AttendanceSummaryController::class, 'getData'])->name('attendance.summary.data');
    Route::get('/attendance-summary/employees', [AttendanceSummaryController::class, 'getEmployees']);
    Route::get('/attendance-summary/layout2',   [AttendanceSummaryController::class, 'getLayout2Data']);
    Route::get('/attendance-summary/areas',                        [AttendanceSummaryController::class, 'getAreas']);
    Route::get('/attendance-summary/areas/{id}/employees',         [AttendanceSummaryController::class, 'getAreaEmployees']);
    Route::get('/attendance-summary/creators', [AttendanceSummaryController::class, 'getCreators']);

    Route::post('/attendance-summary/areas', [AttendanceSummaryController::class, 'saveArea'])
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

});
