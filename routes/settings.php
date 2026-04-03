<?php

use App\Http\Controllers\Settings\AdminAccountController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RegionController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::middleware('role.owner_or_it')->group(function () {
        Route::post('settings/regions', [RegionController::class, 'store'])->name('settings.regions.store');
        Route::patch('settings/regions/{region}', [RegionController::class, 'update'])->name('settings.regions.update');
        Route::delete('settings/regions/{region}', [RegionController::class, 'destroy'])->name('settings.regions.destroy');

        Route::post('settings/admins', [AdminAccountController::class, 'store'])->name('settings.admins.store');
        Route::patch('settings/admins/{user}', [AdminAccountController::class, 'update'])->name('settings.admins.update');
        Route::delete('settings/admins/{user}', [AdminAccountController::class, 'destroy'])->name('settings.admins.destroy');
    });

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
