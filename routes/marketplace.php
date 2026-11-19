<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\RequestBoardController;
use App\Http\Controllers\StudioGigController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'home'])->name('home');
Route::get('/jasa', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/jasa/{gig}', [CatalogController::class, 'show'])->whereNumber('gig')->name('catalog.show');
Route::get('/jasa/{gig}/portofolio', [CatalogController::class, 'portfolio'])->whereNumber('gig')->name('catalog.portfolio');

Route::get('/permintaan', [RequestBoardController::class, 'index'])->name('requests.index');
Route::get('/permintaan/{serviceRequest}', [RequestBoardController::class, 'show'])
    ->whereNumber('serviceRequest')->name('requests.show');

Route::middleware('auth:web')->group(function (): void {
    Route::get('/permintaan/buat', [RequestBoardController::class, 'create'])->name('requests.create');
    Route::post('/permintaan', [RequestBoardController::class, 'store'])->name('requests.store');
    Route::get('/permintaan/{serviceRequest}/edit', [RequestBoardController::class, 'edit'])
        ->whereNumber('serviceRequest')->name('requests.edit');
    Route::patch('/permintaan/{serviceRequest}', [RequestBoardController::class, 'update'])
        ->whereNumber('serviceRequest')->name('requests.update');
    Route::post('/permintaan/{serviceRequest}/tutup', [RequestBoardController::class, 'close'])
        ->whereNumber('serviceRequest')->name('requests.close');
    Route::post('/permintaan/{serviceRequest}/penawaran', [OfferController::class, 'store'])
        ->whereNumber('serviceRequest')->name('offers.store');
    Route::patch('/penawaran/{offer}', [OfferController::class, 'update'])->whereNumber('offer')->name('offers.update');
    Route::post('/penawaran/{offer}/tarik', [OfferController::class, 'withdraw'])->whereNumber('offer')->name('offers.withdraw');

    Route::get('/studio/jasa', [StudioGigController::class, 'index'])->name('studio.gigs.index');
    Route::get('/studio/jasa/buat', [StudioGigController::class, 'create'])->name('studio.gigs.create');
    Route::post('/studio/jasa', [StudioGigController::class, 'store'])->name('studio.gigs.store');
    Route::get('/studio/jasa/{gig}/edit', [StudioGigController::class, 'edit'])->whereNumber('gig')->name('studio.gigs.edit');
    Route::patch('/studio/jasa/{gig}', [StudioGigController::class, 'update'])->whereNumber('gig')->name('studio.gigs.update');
    Route::post('/studio/jasa/{gig}/status', [StudioGigController::class, 'toggle'])->whereNumber('gig')->name('studio.gigs.toggle');
    Route::get('/studio/penawaran', [OfferController::class, 'index'])->name('studio.offers.index');
});
