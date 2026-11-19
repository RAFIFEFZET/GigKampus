<?php

namespace App\Services\Orders;

use App\Enums\PaymentMethod;
use App\Enums\PriceOfferStatus;
use App\Enums\TransactionType;
use App\Exceptions\CheckoutConflict;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Audit;
use App\Services\Inbox;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class OfferAcceptanceService
{
    public function __construct(private readonly CheckoutOrder $orders) {}

    public function canAccept(User $buyer, PriceOffer $offer, ?CarbonInterface $at = null): bool
    {
        $request = $offer->serviceRequest;

        return $buyer->hasId($request->buyer_id)
            && $buyer->canCreateNewTransaction()
            && $offer->seller->canSubmitPriceOffer($request, $at)
            && $offer->canBeEdited($request, $at)
            && $offer->offered_price <= $request->budget_max;
    }

    public function accept(User $buyer, PriceOffer $offer, string $key, int $requestVersion, int $offerVersion, PaymentMethod $method): Order
    {
        abort_unless($buyer->hasId($offer->serviceRequest->buyer_id), 404);

        return DB::transaction(function () use ($buyer, $offer, $key, $requestVersion, $offerVersion, $method): Order {
            $accounts = $this->orders->lockAccounts($buyer->id, $offer->seller_id);
            $buyer = $accounts->get($buyer->id);
            $seller = $accounts->get($offer->seller_id);

            if ($existing = $this->orders->findRetry($buyer, $offer, $key)) {
                return $existing;
            }

            abort_unless($buyer->canCreateNewTransaction(), 403);
            $request = ServiceRequest::query()->lock('for no key update')->findOrFail($offer->service_request_id);
            abort_unless($buyer->hasId($request->buyer_id), 404);
            $request->setRelation('buyer', $buyer);
            $offers = $request->priceOffers()
                ->where(fn ($query) => $query->whereKey($offer->id)->orWhere('status', PriceOfferStatus::Pending))
                ->orderBy('id')->lock('for no key update')->get()->keyBy('id');
            $offer = $offers->get($offer->id);
            $offer->setRelation('serviceRequest', $request);
            $offer->setRelation('seller', $seller);

            if ($request->version !== $requestVersion || $offer->version !== $offerVersion) {
                throw new CheckoutConflict('Ketentuan permintaan atau penawaran telah berubah. Tinjau kembali detail terbaru.');
            }

            $now = now();
            if (! $this->canAccept($buyer, $offer, $now)) {
                throw new CheckoutConflict('Penawaran ini sudah tidak dapat diterima.');
            }

            $before = $offer->only(['status', 'version']);
            $offer->accept($request, $buyer, $seller, $offerVersion, $now);
            $offer->save();
            Audit::record($buyer, 'offer.accepted', $offer, $before, $offer->only(['status', 'version']));

            $before = $request->only(['status']);
            $request->markOrdered($now);
            $request->save();
            Audit::record($buyer, 'request.ordered', $request, $before, $request->only(['status']));

            $reason = 'Buyer menerima penawaran lain.';
            foreach ($offers->except($offer->id) as $pending) {
                $before = $pending->only(['status', 'version']);
                $pending->reject($reason);
                $pending->save();
                Audit::record($buyer, 'offer.rejected', $pending, $before, $pending->only(['status', 'version']), $reason);
                Inbox::offer($pending->seller_id, 'offer.rejected', 'Penawaran ditolak', $reason, $pending);
            }

            return $this->orders->create($buyer, $seller, $key, $method, [
                'transaction_type' => TransactionType::CustomRequest,
                'gig_id' => null,
                'price_offer_id' => $offer->id,
                'title_snapshot' => $request->title,
                'scope_description_snapshot' => $request->description,
                'category_name_snapshot' => $request->category()->sharedLock()->firstOrFail()->name,
                'seller_proposal_snapshot' => $offer->proposal_note,
                'total_price' => $offer->offered_price,
                'max_revisions_snapshot' => $offer->max_revisions,
                'delivery_deadline_at' => CarbonImmutable::parse($request->deadline_date->toDateString(), 'Asia/Jakarta')->endOfDay(),
            ]);
        }, 3);
    }
}
