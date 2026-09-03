<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('cadastro', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('cadastro', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');

    Route::get('esqueci-a-senha', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('esqueci-a-senha', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('redefinir-senha/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('redefinir-senha', [NewPasswordController::class, 'store'])->name('password.store');
});

// Etapa intermediária: senha validada, aguardando o código do segundo fator.
Route::middleware(['guest', 'two-factor.pending'])->group(function () {
    Route::get('verificacao', [TwoFactorChallengeController::class, 'create'])->name('two-factor.create');
    Route::post('verificacao', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('two-factor.store');
    Route::post('verificacao/reenviar', [TwoFactorChallengeController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('two-factor.resend');
    Route::delete('verificacao', [TwoFactorChallengeController::class, 'destroy'])->name('two-factor.destroy');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
