<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\SpedBatchController;
use App\Http\Controllers\SpedController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('painel', DashboardController::class)->name('dashboard');

    Route::get('sped', [SpedController::class, 'index'])->name('sped.index');
    Route::post('sped', [SpedController::class, 'store'])->name('sped.store');

    // Envio em lote: um arquivo por requisição.
    Route::post('sped/lotes', [SpedBatchController::class, 'store'])->name('sped.batch.store');
    Route::post('sped/lotes/{conversion}/arquivos', [SpedBatchController::class, 'upload'])->name('sped.batch.upload');
    Route::post('sped/lotes/{conversion}/converter', [SpedBatchController::class, 'convert'])->name('sped.batch.convert');
    Route::delete('sped/lotes/{conversion}', [SpedBatchController::class, 'destroy'])->name('sped.batch.destroy');

    Route::prefix('configuracoes')->name('settings.')->group(function () {
        Route::get('perfil', [ProfileController::class, 'edit'])->name('profile');
        Route::patch('perfil', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('senha', [SecurityController::class, 'updatePassword'])->name('password.update');
        Route::put('dois-fatores', [SecurityController::class, 'updateTwoFactor'])->name('two-factor.update');
    });
});

// Fora do grupo autenticado de propósito: a assinatura é a credencial, para o
// download sobreviver à expiração da sessão e às retentativas do navegador.
Route::get('sped/{conversion}/download', [SpedController::class, 'download'])
    ->middleware('signed')
    ->name('sped.download');

require __DIR__.'/auth.php';
