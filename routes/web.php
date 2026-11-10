<?php

declare(strict_types=1);

use App\Http\Controllers\TenantSwitchController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->middleware('tenant.required')->name('dashboard');
    Route::post('tenant/switch', TenantSwitchController::class)->name('tenant.switch');

    Route::middleware('super')->prefix('platform')->group(function () {
        Volt::route('tenants', 'pages.platform.tenants')->name('platform.tenants');
    });
});

Route::view('profile', 'profile')->middleware(['auth'])->name('profile');

require __DIR__.'/auth.php';
