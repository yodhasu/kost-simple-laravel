<?php

use App\Http\Controllers\KostAppController;
use Illuminate\Support\Facades\Artisan;
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
    Route::get('transactions', [KostAppController::class, 'transactions'])->name('transactions.index')->middleware('role.owner_or_it');
    Route::get('export', [KostAppController::class, 'export'])->name('export.index');
    Route::get('settings', [KostAppController::class, 'settings'])->name('kost.settings')->middleware('role.owner_or_it');
});

require __DIR__.'/settings.php';

Route::get('/internal/run-overdue-check/{token}', function (string $token) {
    abort_unless(hash_equals((string) env('CRON_TOKEN'), $token), 403);

    Artisan::call('tenants:check-overdue');

    return nl2br(e(Artisan::output()));
});
