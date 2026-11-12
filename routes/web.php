<?php

declare(strict_types=1);

use App\Http\Controllers\TenantSwitchController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('dashboard', 'pages.dashboard')->middleware('tenant.required')->name('dashboard');
    Route::middleware('tenant.required')->group(function () {
        Volt::route('audience', 'pages.audience.index')->name('audience');
        Volt::route('audience/import', 'pages.audience.import')->name('audience.import');
        Volt::route('audience/imports/{run}', 'pages.audience.import-show')->name('audience.imports.show');
        Volt::route('audience/suppressions', 'pages.audience.suppressions')->name('audience.suppressions');
        Volt::route('content', 'pages.content.index')->name('content');
    });

    Route::post('tenant/switch', TenantSwitchController::class)->name('tenant.switch');

    Route::middleware('super')->prefix('platform')->group(function () {
        Volt::route('tenants', 'pages.platform.tenants')->name('platform.tenants');
    });
});

Route::view('profile', 'profile')->middleware(['auth'])->name('profile');

require __DIR__.'/auth.php';
