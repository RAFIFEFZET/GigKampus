<?php

use App\Http\Controllers\Orders\OrderCancellationController;
use App\Http\Controllers\Orders\OrderPaymentController;
use App\Http\Controllers\Orders\OrderRefundController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('pesanan/{order}')->where(['order' => '[0-9]+'])->name('orders.')->group(function (): void {
    Route::prefix('pembayaran')->name('payment.')->group(function (): void {
        Route::patch('/metode', [OrderPaymentController::class, 'selectMethod'])->name('method');
        Route::post('/bukti', [OrderPaymentController::class, 'storeProof'])->name('proofs.store');
        Route::post('/bukti/{paymentProof}/validasi', [OrderPaymentController::class, 'verifyProof'])
            ->whereNumber('paymentProof')->name('proofs.verify');
        Route::post('/bukti/{paymentProof}/tolak', [OrderPaymentController::class, 'rejectProof'])
            ->whereNumber('paymentProof')->name('proofs.reject');
    });

    Route::prefix('pembatalan')->name('cancel.')->group(function (): void {
        Route::post('/langsung', [OrderCancellationController::class, 'cancelUnpaid'])->name('unpaid');
        Route::post('/ajukan', [OrderCancellationController::class, 'request'])->name('request');
        Route::post('/setujui', [OrderCancellationController::class, 'approve'])->name('approve');
        Route::post('/tolak', [OrderCancellationController::class, 'reject'])->name('reject');
        Route::post('/tarik', [OrderCancellationController::class, 'withdraw'])->name('withdraw');
    });

    Route::post('/refund/konfirmasi', [OrderRefundController::class, 'confirm'])->name('refund.confirm');
});
