<?php
use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BioManagementController;

$app_name = env('APP_NAME', '');
Route::redirect('/', "/$app_name");

Route::prefix($app_name)->middleware(AuthMiddleware::class)->group(function () {
    Route::get("biomanagement", [BioManagementController::class, 'index'])->name('BioManagement');
    Route::post("/biometric-management/import", [BioManagementController::class, 'importLogs'])->name('bio.import');
    Route::get("/biometric-management/manual-logs", [BioManagementController::class, 'getManualLogs'])->name('bio.manual-logs');
    Route::post("/biometric-management/add-manual-log", [BioManagementController::class, 'addManualLog'])->name('bio.add-manual-log');
    Route::get("/biometric-management/search-employees", [BioManagementController::class, 'searchEmployees'])->name('bio.search-employees');
    Route::get('/biometric-management/ob-dates',              [BioManagementController::class, 'getObDates'])->name('bio.ob-dates');
    Route::get('/biometric-management/ob-employees',          [BioManagementController::class, 'getObEmployees'])->name('bio.ob-employees');
    Route::get('/biometric-management/newly-hired-dates',     [BioManagementController::class, 'getNewlyHiredDates'])->name('bio.newly-hired-dates');
    Route::get('/biometric-management/newly-hired-employees', [BioManagementController::class, 'getNewlyHiredEmployees'])->name('bio.newly-hired-employees');
    Route::get('/biometric-management/ftw-dates',             [BioManagementController::class, 'getFtwDates'])->name('bio.ftw-dates');
    Route::get('/biometric-management/ftw-employees',         [BioManagementController::class, 'getFtwEmployees'])->name('bio.ftw-employees');
    Route::get('/biometric-management/export',          [BioManagementController::class, 'exportLogs'])->name('bio.export');
    Route::get('/biometric-management/export-progress', [BioManagementController::class, 'exportProgress'])->name('bio.export-progress');
    Route::get('/biometric-management/export-download', [BioManagementController::class, 'exportDownload'])->name('bio.export-download');
    Route::get('/biometric-management/export-timing', [BioManagementController::class, 'exportTiming'])->name('bio.export-timing');
});