<?php

use App\Http\Controllers\KoboController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::prefix('kobo/{token}')->group(function (): void {
    Route::post('v1/auth/device', [KoboController::class, 'authDevice'])->name('kobo.auth.device');
    Route::post('v1/auth/refresh', [KoboController::class, 'authRefresh'])->name('kobo.auth.refresh');
    Route::get('v1/initialization', [KoboController::class, 'initialization'])->name('kobo.initialization');
    Route::get('v1/library/sync', [KoboController::class, 'sync'])->name('kobo.library.sync');
    Route::get('v1/library/{bookId}/metadata', [KoboController::class, 'metadata'])->name('kobo.library.metadata');
    Route::get('v1/library/{bookId}/state', [KoboController::class, 'getState'])->name('kobo.library.state.show');
    Route::put('v1/library/{bookId}/state', [KoboController::class, 'putState'])->name('kobo.library.state.update');
    Route::delete('v1/library/{bookId}', [KoboController::class, 'deleteEntitlement'])->name('kobo.library.delete');
    Route::match(['post', 'put'], 'v1/analytics/{path?}', [KoboController::class, 'analytics'])->where('path', '.*')->name('kobo.analytics');
    Route::get('v1/books/{bookId}/download', [KoboController::class, 'download'])->name('kobo.books.download');
    Route::any('{path}', [KoboController::class, 'stub'])->where('path', '.*')->name('kobo.stub');
});

require __DIR__.'/auth.php';
