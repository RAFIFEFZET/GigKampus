<?php

use App\Http\Controllers\Catalog\SellerProfileController;
use App\Http\Controllers\Orders\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/penjual/{seller}', SellerProfileController::class)->whereNumber('seller')->name('seller.show');
Route::get('/pesanan', [OrderController::class, 'index'])->middleware('auth:web')->name('orders.index');
Route::get('/studio', [OrderController::class, 'studio'])->middleware('auth:web')->name('studio.index');

Route::middleware('auth:web,admin')->prefix('pesanan/{order}')->where(['order' => '[0-9]+'])->name('orders.')->group(function (): void {
    Route::get('/', [OrderController::class, 'show'])->name('show');
    Route::get('/pembayaran', [OrderController::class, 'payment'])->name('payment.show');
    Route::get('/pembayaran/qris', [OrderController::class, 'qris'])->name('payment.qris');
});
