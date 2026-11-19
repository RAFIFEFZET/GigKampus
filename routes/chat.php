<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->group(function (): void {
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{room}', [ChatController::class, 'show'])->whereNumber('room')->name('chat.show');
    Route::post('/jasa/{gig}/chat', [ChatController::class, 'gig'])->whereNumber('gig')->name('chat.gig');
    Route::post('/penawaran/{offer}/chat', [ChatController::class, 'offer'])->whereNumber('offer')->name('chat.offer');
    Route::get('/pesanan/{order}/chat', [ChatController::class, 'order'])->whereNumber('order')->name('orders.chat');
    Route::post('/chat/{room}/pesan', [ChatController::class, 'store'])->whereNumber('room')->name('chat.messages.store');
    Route::post('/chat/{room}/baca', [ChatController::class, 'read'])->whereNumber('room')->name('chat.read');
});
