<?php

use App\Http\Controllers\OrderWorkController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('pesanan/{order}')->where(['order' => '[0-9]+'])->name('orders.')
    ->controller(OrderWorkController::class)->group(function (): void {
        Route::post('/hasil', 'deliver')->name('deliveries.store');
        Route::post('/revisi', 'requestRevision')->name('revisions.store');
        Route::post('/revisi/{revision}/mulai', 'startRevision')->whereNumber('revision')->name('revisions.start');
        Route::post('/selesai', 'complete')->name('complete');
        Route::post('/ulasan', 'review')->name('reviews.store');
    });
