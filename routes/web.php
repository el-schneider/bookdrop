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

Route::get('library/books/{book}/annotations', function (string $book) {
    return view('book-annotations', ['book' => $book]);
})->middleware(['auth', 'verified'])->name('library.books.annotations');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

/*
 * Kobo store API. The device is configured with this tokenised base as its api_endpoint.
 */
Route::prefix('kobo/{token}')->middleware(RecordKoboTraffic::class)->group(function (): void {
    Route::post('v1/auth/device', [KoboController::class, 'authDevice'])->name('kobo.auth.device');
    Route::post('v1/auth/refresh', [KoboController::class, 'authRefresh'])->name('kobo.auth.refresh');
    Route::get('v1/initialization', [KoboController::class, 'initialization'])->name('kobo.initialization');
    Route::get('v1/library/sync', [KoboController::class, 'sync'])->name('kobo.library.sync');
    Route::get('v1/library/{bookId}/metadata', [KoboController::class, 'metadata'])->name('kobo.library.metadata');
    Route::get('v1/library/{bookId}/state', [KoboController::class, 'getState'])->name('kobo.library.state.show');
    Route::put('v1/library/{bookId}/state', [KoboController::class, 'putState'])->name('kobo.library.state.update');
    Route::delete('v1/library/{bookId}', [KoboController::class, 'deleteEntitlement'])->name('kobo.library.delete');
    Route::match(['get', 'post'], 'v1/analytics/gettests', [KoboController::class, 'analyticsTests'])->name('kobo.analytics.gettests');
    Route::get('v1/user/loyalty/benefits', [KoboController::class, 'loyaltyBenefits'])->name('kobo.user.loyalty.benefits');
    Route::match(['post', 'put'], 'v1/analytics/{path?}', [KoboController::class, 'analytics'])->where('path', '.*')->name('kobo.analytics');
    Route::get('v1/books/{bookId}/download', [KoboController::class, 'download'])->name('kobo.books.download');
    Route::get('{bookId}/{width}/{height}/{isGreyscale}/image.jpg', [KoboController::class, 'cover'])->name('kobo.books.cover');
    Route::get('{bookId}/{width}/{height}/{quality}/{isGreyscale}/image.jpg', [KoboController::class, 'cover'])->name('kobo.books.cover.quality');

    // Must stay last: it answers anything else the device asks of the store API.
    Route::any('{path}', [KoboController::class, 'stub'])->where('path', '.*')->name('kobo.stub');
});

/*
 * Kobo reading services (annotations).
 *
 * These live at the site ROOT, not under the tokenised prefix: the device resolves
 * `reading_services_host` as an origin and discards any path, so tokenised routes are unreachable.
 *
 * Annotations are stored here and served back with an ETag. Storing is not optional: once the
 * device has uploaded successfully it treats the server as authoritative, so a server that keeps
 * nothing makes the device delete its own copy.
 */
Route::middleware(RecordKoboTraffic::class)->group(function (): void {
    Route::post('api/v3/content/checkforchanges', [KoboAnnotationController::class, 'checkForChanges'])
        ->name('kobo.readingservices.changes');
    Route::get('api/v3/content/{contentId}/annotations', [KoboAnnotationController::class, 'index'])
        ->name('kobo.readingservices.index');
    Route::match(['patch', 'put'], 'api/v3/content/{contentId}/annotations', [KoboAnnotationController::class, 'update'])
        ->name('kobo.readingservices.update');

    // Anything else reading services asks for is logged rather than silently swallowed. A 404 on
    // these other paths is tolerated by the device; a 404 on the three routes above is not.
    Route::any('api/v3/{path}', [KoboReadingServicesController::class, 'fallback'])
        ->where('path', '.*')->name('kobo.readingservices.fallback');
});

require __DIR__.'/auth.php';
