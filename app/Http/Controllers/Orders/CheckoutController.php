<?php

namespace App\Http\Controllers\Orders;

use App\Enums\PaymentMethod;
use App\Exceptions\CheckoutConflict;
use App\Http\Controllers\Controller;
use App\Models\Gig;
use App\Models\PriceOffer;
use App\Services\Orders\CheckoutOrder;
use App\Services\Orders\DirectCheckoutService;
use App\Services\Orders\OfferAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CheckoutController extends Controller
{
    private const VERSION_RULES = ['required', 'integer', 'between:1,2147483647'];

    public function __construct(
        private readonly DirectCheckoutService $direct,
        private readonly OfferAcceptanceService $offers,
        private readonly CheckoutOrder $orders,
    ) {}

    public function showGig(Request $request, Gig $gig): Response
    {
        return $this->render($request, $gig);
    }

    public function storeGig(Request $request, Gig $gig): RedirectResponse|Response
    {
        $checkout = $request->validate([
            ...$this->checkoutRules(),
            'gig_version' => self::VERSION_RULES,
            'buyer_brief' => ['required', 'string', 'min:20', 'max:5000', 'not_regex:/\x00/'],
        ]);

        try {
            $order = $this->direct->checkout(
                $request->user(),
                $gig,
                $checkout['creation_key'],
                (int) $checkout['gig_version'],
                PaymentMethod::from($checkout['payment_method']),
                $checkout['buyer_brief'],
            );
        } catch (CheckoutConflict $conflict) {
            return $this->render($request, $gig->refresh(), $checkout, $conflict);
        }

        return to_route('orders.show', $order);
    }

    public function showOffer(Request $request, PriceOffer $offer): Response
    {
        return $this->render($request, $offer);
    }

    public function storeOffer(Request $request, PriceOffer $offer): RedirectResponse|Response
    {
        abort_unless($request->user()->hasId($offer->serviceRequest->buyer_id), 404);
        $checkout = $request->validate([
            ...$this->checkoutRules(),
            'request_version' => self::VERSION_RULES,
            'offer_version' => self::VERSION_RULES,
            'buyer_brief' => ['prohibited'],
        ]);

        try {
            $order = $this->offers->accept(
                $request->user(),
                $offer,
                $checkout['creation_key'],
                (int) $checkout['request_version'],
                (int) $checkout['offer_version'],
                PaymentMethod::from($checkout['payment_method']),
            );
        } catch (CheckoutConflict $conflict) {
            return $this->render($request, $offer->refresh(), $checkout, $conflict);
        }

        return to_route('orders.show', $order);
    }

    private function checkoutRules(): array
    {
        return [
            'creation_key' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }

    private function render(Request $request, Gig|PriceOffer $source, array $checkout = [], ?CheckoutConflict $conflict = null): Response
    {
        $gig = $source instanceof Gig ? $source->loadMissing(['seller', 'category']) : null;
        $offer = $source instanceof PriceOffer
            ? $source->loadMissing(['serviceRequest.buyer', 'serviceRequest.category', 'seller'])
            : null;
        $seller = $source->seller;
        if ($gig !== null) {
            if (Gate::denies('order', $gig)) {
                abort_unless($conflict?->preserveBrief, 403);
                $brief = $checkout['buyer_brief'];
                $message = 'Jasa ini sudah tidak tersedia untuk checkout. Anda dapat menyalin kembali brief berikut.';

                return $request->expectsJson()
                    ? response(['message' => $message, 'buyer_brief' => $brief], 409)
                    : response()->view('pages.orders.checkout-unavailable', compact('brief', 'message'), 409);
            }
            $canSubmit = true;
        } else {
            abort_unless($request->user()->hasId($offer->serviceRequest->buyer_id), 404);
            $canSubmit = $this->offers->canAccept($request->user(), $offer);
            abort_unless($canSubmit || $conflict !== null, 403);
        }

        $key = $conflict?->replaceCreationKey ? (string) Str::uuid() : ($checkout['creation_key'] ?? (string) Str::uuid());
        if ($conflict !== null && $request->expectsJson()) {
            return response(['message' => $conflict->getMessage(), 'creation_key' => $key], 409);
        }

        return response()->view('pages.orders.checkout', [
            'gig' => $gig,
            'offer' => $offer,
            'serviceRequest' => $offer?->serviceRequest,
            'seller' => $seller,
            'methods' => $this->orders->availableMethods($seller),
            'creationKey' => $key,
            'conflict' => $conflict?->getMessage(),
            'brief' => $checkout['buyer_brief'] ?? '',
            'selectedMethod' => $checkout['payment_method'] ?? null,
            'canSubmit' => $canSubmit,
        ], $conflict === null ? 200 : 409);
    }
}
