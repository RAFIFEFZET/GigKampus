<?php

use App\Http\Controllers\Orders\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->group(function (): void {
    Route::get('/jasa/{gig}/checkout', [CheckoutController::class, 'showGig'])->whereNumber('gig')->name('checkout.gig');
    Route::post('/jasa/{gig}/checkout', [CheckoutController::class, 'storeGig'])->whereNumber('gig')->name('checkout.gig.store');
    Route::get('/penawaran/{offer}/checkout', [CheckoutController::class, 'showOffer'])->whereNumber('offer')->name('checkout.offer');
    Route::post('/penawaran/{offer}/checkout', [CheckoutController::class, 'storeOffer'])->whereNumber('offer')->name('checkout.offer.store');
});
