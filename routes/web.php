<?php

use App\Http\Controllers\KostAppController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [KostAppController::class, 'dashboard'])->name('dashboard');
    Route::get('tenants', [KostAppController::class, 'tenants'])->name('tenants.index');
    Route::get('payments', [KostAppController::class, 'payments'])->name('payments.index');
    Route::get('export', [KostAppController::class, 'export'])->name('export.index');
    Route::get('settings', [KostAppController::class, 'settings'])->name('kost.settings')->middleware('role.owner_or_it');
});

require __DIR__.'/settings.php';
