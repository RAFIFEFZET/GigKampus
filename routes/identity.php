<?php

use App\Http\Controllers\Identity\AuthenticationController;
use App\Http\Controllers\Identity\PaymentMethodsController;
use App\Http\Controllers\Identity\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest:web,admin')->group(function (): void {
    Route::view('/login', 'pages.auth.login')->name('login');
    Route::post('/login', [AuthenticationController::class, 'login'])->name('login.store');
    Route::view('/register', 'pages.auth.register')->name('register');
    Route::post('/register', [AuthenticationController::class, 'register'])->name('register.store');
});

Route::post('/logout', [AuthenticationController::class, 'logout'])->middleware('auth:web,admin')->name('logout');

Route::middleware('auth:web')->group(function (): void {
    Route::get('/profil', [ProfileController::class, 'show'])->name('profile');
    Route::patch('/profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profil/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::get('/studio/rek-qr', [PaymentMethodsController::class, 'show'])->name('studio.payment');
    Route::patch('/studio/rek-qr', [PaymentMethodsController::class, 'update'])->name('studio.payment.update');
    Route::delete('/studio/rek-qr/{method}', [PaymentMethodsController::class, 'destroy'])
        ->whereIn('method', ['bank', 'qris'])->name('studio.payment.destroy');
});
