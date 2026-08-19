<?php

use App\Http\Controllers\KoboAnnotationController;
use App\Http\Controllers\KoboController;
use App\Http\Controllers\KoboReadingServicesController;
use App\Http\Controllers\LibraryCoverController;
use App\Http\Middleware\RecordKoboTraffic;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('library', 'library')
    ->middleware(['auth', 'verified'])
    ->name('library');

Route::get('library/books/{book}/cover', LibraryCoverController::class)
    ->middleware(['auth', 'verified'])
    ->name('library.books.cover');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::prefix('kobo/{token}')->middleware(RecordKoboTraffic::class)->group(function (): void {
    Route::post('v1/auth/device', [KoboController::class, 'authDevice'])->name('kobo.auth.device');
    Route::post('v1/auth/refresh', [KoboController::class, 'authRefresh'])->name('kobo.auth.refresh');
    Route::get('v1/initialization', [KoboController::class, 'initialization'])->name('kobo.initialization');
    Route::get('v1/library/sync', [KoboController::class, 'sync'])->name('kobo.library.sync');
    Route::get('v1/library/{bookId}/metadata', [KoboController::class, 'metadata'])->name('kobo.library.metadata');
    Route::get('v1/library/{bookId}/state', [KoboController::class, 'getState'])->name('kobo.library.state.show');
    Route::put('v1/library/{bookId}/state', [KoboController::class, 'putState'])->name('kobo.library.state.update');
    Route::delete('v1/library/{bookId}', [KoboController::class, 'deleteEntitlement'])->name('kobo.library.delete');
    // Annotation storage, kept mounted here so its ETag handshake stays under test. The device
    // never reaches these: it resolves reading_services_host as an origin, so the real traffic
    // arrives at the root routes below. Promote this logic there once an observed sync confirms
    // the request shape and credentials.
    Route::post('api/v3/content/checkforchanges', [KoboAnnotationController::class, 'checkForChanges'])->name('kobo.annotations.changes');
    Route::get('api/v3/content/{contentId}/annotations', [KoboAnnotationController::class, 'index'])->name('kobo.annotations.index');
    Route::match(['patch', 'put'], 'api/v3/content/{contentId}/annotations', [KoboAnnotationController::class, 'update'])->name('kobo.annotations.update');

    Route::match(['get', 'post'], 'v1/analytics/gettests', [KoboController::class, 'analyticsTests'])->name('kobo.analytics.gettests');
    Route::get('v1/user/loyalty/benefits', [KoboController::class, 'loyaltyBenefits'])->name('kobo.user.loyalty.benefits');
    Route::match(['post', 'put'], 'v1/analytics/{path?}', [KoboController::class, 'analytics'])->where('path', '.*')->name('kobo.analytics');
    Route::get('v1/books/{bookId}/download', [KoboController::class, 'download'])->name('kobo.books.download');
    Route::get('{bookId}/{width}/{height}/{isGreyscale}/image.jpg', [KoboController::class, 'cover'])->name('kobo.books.cover');
    Route::get('{bookId}/{width}/{height}/{quality}/{isGreyscale}/image.jpg', [KoboController::class, 'cover'])->name('kobo.books.cover.quality');
    Route::any('{path}', [KoboController::class, 'stub'])->where('path', '.*')->name('kobo.stub');
});

/*
 * Kobo annotation sync (reading services).
 *
 * These live at the site ROOT, not under the tokenised prefix: the device resolves
 * `reading_services_host` as an origin and discards any path, so tokenised annotation routes are
 * unreachable. Requests currently answer in a deliberately inert "parking" shape that cannot cause
 * the device to delete annotations, while the real request shape and credentials are observed.
 */
Route::middleware(RecordKoboTraffic::class)->group(function (): void {
    Route::post('api/v3/content/checkforchanges', [KoboReadingServicesController::class, 'checkForChanges'])
        ->name('kobo.readingservices.changes');
    Route::get('api/v3/content/{contentId}/annotations', [KoboReadingServicesController::class, 'index'])
        ->name('kobo.readingservices.index');
    Route::match(['patch', 'put'], 'api/v3/content/{contentId}/annotations', [KoboReadingServicesController::class, 'update'])
        ->name('kobo.readingservices.update');

    // Anything else the device asks of reading services is logged so the unknown paths stop being
    // invisible. 404 here is tolerated by the device; 404 on the three routes above is not.
    Route::any('api/v3/{path}', [KoboReadingServicesController::class, 'fallback'])
        ->where('path', '.*')->name('kobo.readingservices.fallback');
});

require __DIR__.'/auth.php';
