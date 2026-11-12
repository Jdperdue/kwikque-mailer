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
        Volt::route('campaigns', 'pages.campaigns.index')->name('campaigns');
        Volt::route('campaigns/create', 'pages.campaigns.edit')->name('campaigns.create');
        Volt::route('campaigns/{campaign}', 'pages.campaigns.edit')->name('campaigns.edit');
        Volt::route('campaigns/{campaign}/preview', 'pages.campaigns.preview')->name('campaigns.preview');
        Volt::route('settings', 'pages.settings')->name('settings');
    });

    Route::post('tenant/switch', TenantSwitchController::class)->name('tenant.switch');

    Route::middleware('super')->prefix('platform')->group(function () {
        Volt::route('tenants', 'pages.platform.tenants')->name('platform.tenants');
        Volt::route('tenants/{tenant}/members', 'pages.platform.members')->name('platform.tenants.members');
    });
});

Route::view('profile', 'profile')->middleware(['auth'])->name('profile');

require __DIR__.'/auth.php';
