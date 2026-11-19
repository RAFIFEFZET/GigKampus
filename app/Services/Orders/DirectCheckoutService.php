<?php

namespace App\Services\Orders;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Exceptions\CheckoutConflict;
use App\Models\Gig;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DirectCheckoutService
{
    public function __construct(private readonly CheckoutOrder $orders) {}

    public function checkout(User $buyer, Gig $gig, string $key, int $version, PaymentMethod $method, string $brief): Order
    {
        return DB::transaction(function () use ($buyer, $gig, $key, $version, $method, $brief): Order {
            $accounts = $this->orders->lockAccounts($buyer->id, $gig->seller_id);
            $buyer = $accounts->get($buyer->id);
            $seller = $accounts->get($gig->seller_id);

            if ($existing = $this->orders->findRetry($buyer, $gig, $key)) {
                return $existing;
            }

            abort_unless($buyer->canCreateNewTransaction() && ! $buyer->hasId($seller->id), 403);
            $gig = Gig::query()->lock('for no key update')->findOrFail($gig->id);
            abort_unless($seller->hasId($gig->seller_id), 403);
            $gig->setRelation('seller', $seller);
            $gig->setRelation('category', $gig->category()->sharedLock()->firstOrFail());
            if ($gig->version !== $version) {
                throw new CheckoutConflict('Ketentuan jasa telah berubah. Tinjau kembali detail terbaru sebelum memesan.', preserveBrief: true);
            }
            Gate::forUser($buyer)->authorize('order', $gig);

            return $this->orders->create($buyer, $seller, $key, $method, [
                'transaction_type' => TransactionType::DirectGig,
                'gig_id' => $gig->id,
                'price_offer_id' => null,
                'title_snapshot' => $gig->title,
                'scope_description_snapshot' => $gig->description,
                'category_name_snapshot' => $gig->category->name,
                'buyer_brief' => trim($brief),
                'total_price' => $gig->base_price,
                'max_revisions_snapshot' => $gig->max_revisions,
                'delivery_days_snapshot' => $gig->delivery_days,
            ]);
        }, 3);
    }
}
