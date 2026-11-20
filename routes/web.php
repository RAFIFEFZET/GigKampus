<?php

use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/identity.php';
require __DIR__.'/verification.php';
require __DIR__.'/marketplace.php';
require __DIR__.'/orders.php';
require __DIR__.'/checkout.php';
require __DIR__.'/order-payments.php';
require __DIR__.'/order-work.php';
require __DIR__.'/chat.php';
require __DIR__.'/notifications.php';
require __DIR__.'/reports.php';
require __DIR__.'/channels.php';

Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])
    ->middleware('auth:web,admin')->name('broadcasting.auth');
