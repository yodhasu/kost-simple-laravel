<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\KostController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('api.dashboard');
    Route::post('tenants', [TenantController::class, 'store'])->name('api.tenants.store');
    Route::patch('tenants/{tenant}', [TenantController::class, 'update'])->name('api.tenants.update');
    Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])->name('api.tenants.destroy');

    Route::post('payments', [PaymentController::class, 'store'])->name('api.payments.store');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('api.expenses.store');
    Route::post('incomes', [IncomeController::class, 'store'])->name('api.incomes.store');

    Route::middleware('role.owner_or_it')->group(function () {
        Route::post('kosts', [KostController::class, 'store'])->name('api.kosts.store');
        Route::patch('kosts/{kost}', [KostController::class, 'update'])->name('api.kosts.update');
        Route::delete('kosts/{kost}', [KostController::class, 'destroy'])->name('api.kosts.destroy');
        Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('api.transactions.update');
        Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('api.transactions.destroy');
    });

    Route::get('exports/download', ExportController::class)->name('api.exports.download');
});
