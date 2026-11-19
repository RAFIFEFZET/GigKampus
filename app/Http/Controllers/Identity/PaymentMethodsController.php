<?php

namespace App\Http\Controllers\Identity;

use App\Enums\PaymentMethod;
use App\Models\Bank;
use App\Models\User;
use App\Services\SellerPaymentMethods;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PaymentMethodsController
{
    public function show(Request $request): View
    {
        return view('pages.studio.payment-methods', $this->viewData($request->user()));
    }

    public function update(Request $request, SellerPaymentMethods $methods): RedirectResponse
    {
        $methods->update($request->user(), $request->all());

        return redirect()->route('studio.payment')->with('status', 'Metode pembayaran berhasil disimpan.');
    }

    public function destroy(Request $request, string $method, SellerPaymentMethods $methods): RedirectResponse|Response
    {
        try {
            $methods->remove($request->user(), $method === 'bank' ? PaymentMethod::BankTransfer : PaymentMethod::Qris);
        } catch (ConflictHttpException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return response()->view('pages.studio.payment-methods', [
                ...$this->viewData($request->user()->refresh()),
                'paymentError' => $exception->getMessage(),
            ], 409);
        }

        return redirect()->route('studio.payment')->with('status', 'Metode pembayaran berhasil dihapus.');
    }

    private function viewData(User $seller): array
    {
        return ['seller' => $seller, 'banks' => Bank::query()->orderBy('name')->pluck('name', 'id')->all()];
    }
}
