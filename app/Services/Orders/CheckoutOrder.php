<?php

namespace App\Services\Orders;

use App\Enums\ChatRoomStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\CheckoutConflict;
use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PriceOffer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CheckoutOrder
{
    /** @return Collection<int, User> */
    public function lockAccounts(int $buyerId, int $sellerId): Collection
    {
        // Serialize checkout with account changes and permit foreign keys from other transactions.
        return User::whereKey([$buyerId, $sellerId])->orderBy('id')
            ->lock('for no key update')->get()->keyBy('id');
    }

    public function findRetry(User $buyer, Gig|PriceOffer $source, string $key): ?Order
    {
        // Buyers are already serialized by their account rows. Order keys are also unique across buyers.
        DB::selectOne("SELECT pg_advisory_xact_lock(hashtextextended('gigkampus.order.' || CAST(CAST(? AS uuid) AS text), 0))", [$key]);
        $existing = Order::where('creation_key', $key)->first();
        if ($existing === null) {
            return null;
        }

        $sourceMatches = $source instanceof Gig
            ? $existing->gig_id === $source->id
            : $existing->price_offer_id === $source->id;
        if (! $existing->hasBuyer($buyer) || ! $sourceMatches) {
            throw new CheckoutConflict('Sesi checkout ini sudah digunakan. Tinjau kembali pesanan dan coba lagi.', replaceCreationKey: true);
        }

        return $existing;
    }

    /** @return list<PaymentMethod> */
    public function availableMethods(User $seller): array
    {
        return [
            ...($seller->hasBankPaymentMethod() ? [PaymentMethod::BankTransfer] : []),
            ...($seller->hasQrisPaymentMethod() ? [PaymentMethod::Qris] : []),
        ];
    }

    public function create(User $buyer, User $seller, string $key, PaymentMethod $method, array $contract): Order
    {
        if (! in_array($method, $this->availableMethods($seller), true)) {
            throw new CheckoutConflict('Metode pembayaran yang Anda pilih sudah tidak tersedia. Pilih metode yang tersedia.');
        }

        $order = Order::query()->forceCreate([
            ...$contract,
            'creation_key' => $key,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            ...$this->paymentDestinations($seller),
        ]);

        $order->payment()->save((new Payment)->forceFill([
            'amount' => $order->total_price,
            'payment_method' => $method,
        ]));

        $this->attachRoom($order);
        OrderActivity::record($order, $buyer, 'order.created', 'Pesanan baru', 'Buyer membuat pesanan dan akan mengirim bukti pembayaran.', after: [
            'status' => $order->status->value,
            'stage_version' => $order->stage_version,
            'payment_method' => $method->value,
        ]);

        return $order;
    }

    private function paymentDestinations(User $seller): array
    {
        $hasBank = $seller->hasBankPaymentMethod();
        $hasQris = $seller->hasQrisPaymentMethod();

        return [
            'bank_name_snapshot' => $hasBank ? $seller->bank->name : null,
            'bank_account_number_snapshot' => $hasBank ? $seller->bank_account_number : null,
            'bank_account_holder_name_snapshot' => $hasBank ? $seller->bank_account_holder_name : null,
            'qris_image_path_snapshot' => $hasQris ? $seller->qris_image_path : null,
            'qris_holder_name_snapshot' => $hasQris ? $seller->qris_holder_name : null,
        ];
    }

    private function attachRoom(Order $order): void
    {
        $context = $order->only(['buyer_id', 'seller_id', 'gig_id', 'price_offer_id']);
        $room = ChatRoom::where($context)->whereNull('order_id')->where('status', ChatRoomStatus::Active)
            ->lock('for no key update')->first() ?? (new ChatRoom)->forceFill($context);

        $order->chatRoom()->save($room);
    }
}
