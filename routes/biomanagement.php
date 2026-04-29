<?php
use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BioManagementController;

$app_name = env('APP_NAME', '');
Route::redirect('/', "/$app_name");

Route::prefix($app_name)->middleware(AuthMiddleware::class)->group(function () {
  
    Route::get("biomanagement", [BioManagementController::class, 'index'])->name('BioManagement');
    Route::post("/biometric-management/import", [BioManagementController::class, 'importLogs'])->name('bio.import');

});