<?php

use App\Http\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegisterFingerprintController;

$app_name = env('APP_NAME', '');

Route::prefix($app_name)
    ->middleware(['web', AuthMiddleware::class])
    ->group(function () {

        Route::get('/register-fingerprint',                                         [RegisterFingerprintController::class, 'index'])->name('register-fingerprint');
        Route::post('/register-fingerprint',                                        [RegisterFingerprintController::class, 'store'])->name('register-fingerprint.store');
        Route::get('/register-fingerprint/{employId}/registrations',                [RegisterFingerprintController::class, 'getRegistrations'])->name('register-fingerprint.registrations');
        Route::delete('/register-fingerprint/{employId}/{fingerIndex}',             [RegisterFingerprintController::class, 'destroy'])->name('register-fingerprint.destroy');

        Route::get('/verify-fingerprint/{employId}/templates', [RegisterFingerprintController::class, 'getTemplatesForVerification'])->name('verify-fingerprint.templates');

    });