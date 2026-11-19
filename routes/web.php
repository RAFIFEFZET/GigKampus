<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/identity.php';
require __DIR__.'/verification.php';
require __DIR__.'/marketplace.php';
require __DIR__.'/orders.php';
require __DIR__.'/checkout.php';
require __DIR__.'/order-payments.php';
require __DIR__.'/order-work.php';

Route::middleware('auth:web')->group(function (): void {
    Route::view('/notifikasi', 'pages.inbox.index')->name('inbox.index');

    Route::get('/chat', function (Request $request) {
        abort_unless($request->user()->canCreateNewTransaction(), 403);

        return view('pages.placeholder', ['title' => 'Chat', 'description' => 'Percakapan dengan mahasiswa lain tersedia pada fase berikutnya.']);
    })->name('chat.index');
});
