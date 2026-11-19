<?php

use App\Http\Controllers\AdminCategoryController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminKtmController;
use App\Http\Controllers\KtmVerificationController;
use App\Http\Controllers\PrivateFileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('verifikasi-ktm')->name('ktm.')->group(function (): void {
    Route::view('/', 'pages.ktm.index')->name('index');
    Route::post('/', [KtmVerificationController::class, 'store'])->name('store');
});

Route::middleware('auth:web,admin')->get('/berkas/{jenis}/{id}', PrivateFileController::class)
    ->whereIn('jenis', ['ktm', 'qris', 'qris-pesanan', 'order_qris_snapshot', 'payment_proof', 'order_delivery', 'chat_attachment'])
    ->whereNumber('id')
    ->name('files.show');

Route::middleware('auth:admin')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', AdminDashboardController::class)->name('index');
    Route::get('/ktm', [AdminKtmController::class, 'index'])->name('ktm.index');
    Route::get('/ktm/{verification}', [AdminKtmController::class, 'show'])->whereNumber('verification')->name('ktm.show');
    Route::post('/ktm/{verification}/setujui', [AdminKtmController::class, 'approve'])->whereNumber('verification')->name('ktm.approve');
    Route::post('/ktm/{verification}/tolak', [AdminKtmController::class, 'reject'])->whereNumber('verification')->name('ktm.reject');
    Route::get('/kategori', [AdminCategoryController::class, 'index'])->name('categories.index');
    Route::post('/kategori', [AdminCategoryController::class, 'store'])->name('categories.store');
    Route::patch('/kategori/{category}', [AdminCategoryController::class, 'rename'])->whereNumber('category')->name('categories.rename');
    Route::patch('/kategori/{category}/status', [AdminCategoryController::class, 'changeStatus'])->whereNumber('category')->name('categories.status');
});
