<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/identity.php';
require __DIR__.'/verification.php';
require __DIR__.'/marketplace.php';

Route::middleware('auth:web')->group(function (): void {
    Route::view('/notifikasi', 'pages.inbox.index')->name('inbox.index');
    Route::view('/studio', 'pages.studio.index')->name('studio.index');

    Route::get('/chat', function (Request $request) {
        abort_unless($request->user()->canCreateNewTransaction(), 403);

        return view('pages.placeholder', ['title' => 'Chat', 'description' => 'Percakapan dengan mahasiswa lain tersedia pada fase berikutnya.']);
    })->name('chat.index');

    Route::get('/pesanan', function (Request $request) {
        abort_unless($request->user()->canCreateNewTransaction(), 403);

        return view('pages.placeholder', ['title' => 'Pesanan Saya', 'description' => 'Pembelian dan pengelolaan pesanan tersedia pada fase berikutnya.']);
    })->name('orders.index');
});
