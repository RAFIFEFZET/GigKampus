<?php

namespace Tests\Feature;

use App\Enums\GigStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PriceOfferStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\ChatRoom;
use App\Models\Gig;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class CheckoutTest extends PostgresTestCase
{
    private const BRIEF = "Gunakan bahan acara kampus yang sudah saya siapkan.\nCantumkan jadwal di bagian bawah.";

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 17:30:00', 'UTC'));
    }

    public function test_old_gig_version_keeps_the_brief_and_shows_latest_terms(): void
    {
        $gig = $this->gig($this->student('seller'));
        $url = "/jasa/{$gig->id}/checkout";
        $checkout = $this->actingAs($this->student('buyer'))->get($url)->assertOk();
        $key = $checkout->viewData('creationKey');
        self::assertTrue(Str::isUuid($key));
        $payload = $this->directInput($gig, ['creation_key' => $key]);

        $gig->update(['base_price' => 175000]);
        $this->post($url, $payload)
            ->assertConflict()
            ->assertViewHas('brief', self::BRIEF)
            ->assertViewHas('creationKey', $key)
            ->assertViewHas('gig', fn (Gig $current) => $current->version === 2 && $current->base_price === 175000)
            ->assertSee(self::BRIEF);
        $this->assertDatabaseMissing('orders', ['creation_key' => $key]);
    }

    public function test_direct_checkout_reuses_pre_chat_and_retry_returns_the_owned_immutable_order(): void
    {
        $buyer = $this->student('buyer');
        $seller = $this->student('seller');
        $seller->forceFill([
            'bank_account_number' => '001234567',
            'bank_account_holder_name' => 'Rekening Penjual',
            'qris_image_path' => 'demo/qris/checkout.png',
            'qris_holder_name' => 'Merchant Penjual',
        ])->save();
        $gig = $this->gig($seller);
        $room = ChatRoom::query()->forceCreate(['gig_id' => $gig->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        $url = "/jasa/{$gig->id}/checkout";
        $payload = $this->directInput($gig, [
            'buyer_brief' => "  \r\n".self::BRIEF."\r\n\t ",
            'payment_method' => PaymentMethod::Qris->value,
        ]);

        $created = $this->actingAs($buyer)->post($url, $payload);
        $order = Order::where('creation_key', $payload['creation_key'])->sole();
        $created->assertRedirect(route('orders.show', $order));
        self::assertSame(TransactionType::DirectGig, $order->transaction_type);
        self::assertSame(OrderStatus::AwaitingPayment, $order->status);
        self::assertSame(1, $order->stage_version);
        self::assertSame(self::BRIEF, $order->buyer_brief);
        self::assertSame('Poster Kegiatan Kampus', $order->title_snapshot);
        self::assertSame('Pembuatan poster untuk kegiatan mahasiswa di kampus.', $order->scope_description_snapshot);
        self::assertSame('Kategori Checkout', $order->category_name_snapshot);
        self::assertSame(150000, $order->total_price);
        self::assertSame(2, $order->max_revisions_snapshot);
        self::assertSame(3, $order->delivery_days_snapshot);
        self::assertNull($order->delivery_deadline_at);
        self::assertSame('BCA', $order->bank_name_snapshot);
        self::assertSame('001234567', $order->bank_account_number_snapshot);
        self::assertSame('Rekening Penjual', $order->bank_account_holder_name_snapshot);
        self::assertSame('demo/qris/checkout.png', $order->qris_image_path_snapshot);
        self::assertSame('Merchant Penjual', $order->qris_holder_name_snapshot);
        self::assertSame(PaymentMethod::Qris, $order->payment->payment_method);
        self::assertSame(150000, $order->payment->amount);
        self::assertSame($room->id, $order->chatRoom->id);
        $this->assertDatabaseHas('notifications', ['order_id' => $order->id, 'user_id' => $seller->id, 'type' => 'order.created']);
        $this->assertDatabaseHas('audit_logs', ['order_id' => $order->id, 'actor_user_id' => $buyer->id, 'action' => 'order.created']);

        $seller->forceFill(['bank_account_number' => '998765', 'qris_image_path' => null, 'qris_holder_name' => null])->save();
        $gig->category->forceFill(['is_active' => false])->save();
        $gig->update(['base_price' => 300000]);

        $this->post($url, $payload)->assertRedirect(route('orders.show', $order));
        self::assertSame(1, Order::where('gig_id', $gig->id)->count());
        self::assertSame(1, $order->notifications()->where('type', 'order.created')->count());
        self::assertSame(1, $order->auditLogs()->where('action', 'order.created')->count());
        self::assertSame('001234567', $order->refresh()->bank_account_number_snapshot);
        self::assertSame('demo/qris/checkout.png', $order->qris_image_path_snapshot);
    }

    public function test_self_checkout_inactive_gig_and_suspended_seller_cannot_create_orders(): void
    {
        $buyer = $this->student('buyer');
        $seller = $this->student('seller');
        $gig = $this->gig($seller);
        $url = "/jasa/{$gig->id}/checkout";
        $payload = $this->directInput($gig);

        $this->actingAs($seller)->post($url, $payload)->assertForbidden();
        $gig->forceFill(['status' => GigStatus::Inactive])->save();
        $this->actingAs($buyer)->post($url, $this->directInput($gig))->assertForbidden();
        $gig->forceFill(['status' => GigStatus::Active])->save();
        $seller->forceFill(['is_suspended' => true])->save();
        $this->post($url, $this->directInput($gig))->assertForbidden();
        $seller->forceFill(['is_suspended' => false])->save();
        $buyer->forceFill(['is_suspended' => true])->save();
        $this->post($url, $this->directInput($gig))->assertForbidden();
        $this->assertDatabaseMissing('orders', ['gig_id' => $gig->id]);
    }

    public function test_a_deactivated_gig_preserves_the_stale_brief_without_showing_its_terms(): void
    {
        $gig = $this->gig($this->student('seller'));
        $payload = $this->directInput($gig);
        $url = "/jasa/{$gig->id}/checkout";
        $this->actingAs($this->student('buyer'))->get($url)->assertOk();
        $gig->forceFill(['status' => GigStatus::Inactive])->save();

        $this->post($url, $payload)->assertConflict()->assertSee(self::BRIEF)->assertDontSee($gig->title);
        $this->assertDatabaseMissing('orders', ['gig_id' => $gig->id]);
    }

    public function test_global_creation_key_cannot_expose_another_buyers_order(): void
    {
        $gig = $this->gig($this->student('seller'));
        $url = "/jasa/{$gig->id}/checkout";
        $payload = $this->directInput($gig);
        $this->actingAs($this->student('buyer'))->post($url, $payload)->assertRedirect();
        $this->actingAs($this->student('seller2'))->post($url, $payload)
            ->assertConflict()
            ->assertViewHas('creationKey', fn (string $key) => $key !== $payload['creation_key'] && Str::isUuid($key));
        $gig->forceFill(['status' => GigStatus::Inactive])->save();
        $this->post($url, $payload)->assertForbidden();
        self::assertSame(1, Order::where('gig_id', $gig->id)->count());
    }

    public function test_offer_acceptance_checks_both_versions_before_snapshotting_and_rejecting_pending_losers(): void
    {
        $buyer = $this->student('buyer');
        $seller = $this->student('seller');
        $loser = $this->student('seller2');
        $request = $this->serviceRequest($buyer);
        $winner = $this->offer($request, $seller);
        $pending = $this->offer($request, $loser);
        $room = ChatRoom::query()->forceCreate(['price_offer_id' => $winner->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        $url = "/penawaran/{$winner->id}/checkout";
        $payload = [
            'request_version' => 1,
            'offer_version' => 1,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'creation_key' => (string) Str::uuid(),
        ];

        $this->actingAs($loser)->get($url)->assertNotFound();
        $this->post($url, $payload)->assertNotFound();
        $this->actingAs($buyer)->get($url)->assertOk();
        $seller->forceFill(['is_suspended' => true])->saveOrFail();
        $loser->forceFill(['is_suspended' => true])->saveOrFail();
        $this->get('/permintaan/'.$request->id)->assertOk()->assertDontSeeText('Terima Penawaran');
        $seller->forceFill(['is_suspended' => false])->saveOrFail();
        $loser->forceFill(['is_suspended' => false])->saveOrFail();
        $request->update(['title' => 'Permintaan Poster Baru']);
        $this->post($url, $payload)->assertConflict();
        $winner->update(['offered_price' => 130000]);
        $this->post($url, [...$payload, 'request_version' => 2])->assertConflict();
        $seller->forceFill(['qris_image_path' => null, 'qris_holder_name' => null])->save();
        $this->post($url, [...$payload, 'request_version' => 2, 'offer_version' => 2, 'payment_method' => 'qris'])
            ->assertConflict()
            ->assertViewHas('methods', [PaymentMethod::BankTransfer]);
        $this->assertDatabaseMissing('orders', ['price_offer_id' => $winner->id]);
        self::assertSame(ServiceRequestStatus::Open, $request->refresh()->status);
        self::assertSame(PriceOfferStatus::Pending, $winner->refresh()->status);
        self::assertSame(PriceOfferStatus::Pending, $pending->refresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'offer.accepted', 'entity_id' => $winner->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'offer.rejected', 'entity_id' => $pending->id]);

        $created = $this->post($url, [...$payload, 'request_version' => 2, 'offer_version' => 2]);
        $order = Order::where('price_offer_id', $winner->id)->sole();
        $created->assertRedirect(route('orders.show', $order));
        $this->post($url, [...$payload, 'request_version' => 2, 'offer_version' => 2])->assertRedirect(route('orders.show', $order));
        self::assertSame(TransactionType::CustomRequest, $order->transaction_type);
        self::assertSame(OrderStatus::AwaitingPayment, $order->status);
        self::assertSame('Permintaan Poster Baru', $order->title_snapshot);
        self::assertSame('Membutuhkan poster kegiatan dengan bahan yang sudah disediakan.', $order->scope_description_snapshot);
        self::assertSame('Satu desain utama dan dua revisi sesuai brief permintaan.', $order->seller_proposal_snapshot);
        self::assertSame(130000, $order->total_price);
        self::assertSame(2, $order->max_revisions_snapshot);
        self::assertNull($order->buyer_brief);
        self::assertNull($order->delivery_days_snapshot);
        self::assertSame('2026-11-25 16:59:59.999999', $order->delivery_deadline_at->utc()->format('Y-m-d H:i:s.u'));
        self::assertSame(PaymentMethod::BankTransfer, $order->payment->payment_method);
        self::assertSame($room->id, $order->chatRoom->id);
        self::assertSame(ServiceRequestStatus::Ordered, $request->refresh()->status);
        self::assertSame(PriceOfferStatus::Accepted, $winner->refresh()->status);
        self::assertSame(PriceOfferStatus::Rejected, $pending->refresh()->status);
        self::assertSame(2, $pending->version);
        self::assertNotEmpty($pending->status_reason);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'price_offers', 'entity_id' => $pending->id, 'action' => 'offer.rejected']);
        $this->assertDatabaseHas('notifications', ['user_id' => $loser->id, 'type' => 'offer.rejected', 'body' => $pending->status_reason]);
    }

    public function test_cancelling_an_order_keeps_the_source_request_ordered(): void
    {
        $buyer = $this->student('buyer');
        $request = $this->serviceRequest($buyer);
        $offer = $this->offer($request, $this->student('seller'));
        $url = "/penawaran/{$offer->id}/checkout";
        $payload = [
            'request_version' => 1,
            'offer_version' => 1,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'creation_key' => (string) Str::uuid(),
        ];

        $this->actingAs($buyer)->post($url, $payload)->assertRedirect();
        $order = Order::where('price_offer_id', $offer->id)->sole();
        $request->category->forceFill(['is_active' => false])->save();
        $this->post(route('orders.cancel.unpaid', $order))->assertRedirect();

        self::assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        self::assertSame(ServiceRequestStatus::Ordered, $request->refresh()->status);
        self::assertSame(PriceOfferStatus::Accepted, $offer->refresh()->status);
        $this->post("/permintaan/{$request->id}/tutup")->assertForbidden();
        $this->post($url, [...$payload, 'offer_version' => 2, 'creation_key' => (string) Str::uuid()])->assertConflict();
        $this->post($url, $payload)->assertRedirect(route('orders.show', $order));
        self::assertSame(1, Order::where('price_offer_id', $offer->id)->count());
    }

    private function student(string $key): User
    {
        return User::where('email', $key.'.demo@apps.ipb.ac.id')->sole();
    }

    private function category(): Category
    {
        return Category::create(['name' => 'Kategori Checkout']);
    }

    private function gig(User $seller): Gig
    {
        return Gig::query()->forceCreate([
            'seller_id' => $seller->id,
            'category_id' => $this->category()->id,
            'title' => 'Poster Kegiatan Kampus',
            'description' => 'Pembuatan poster untuk kegiatan mahasiswa di kampus.',
            'base_price' => 150000,
            'delivery_days' => 3,
            'max_revisions' => 2,
        ]);
    }

    private function serviceRequest(User $buyer): ServiceRequest
    {
        return ServiceRequest::query()->forceCreate([
            'buyer_id' => $buyer->id,
            'category_id' => $this->category()->id,
            'title' => 'Permintaan Poster Kampus',
            'description' => 'Membutuhkan poster kegiatan dengan bahan yang sudah disediakan.',
            'budget_min' => 100000,
            'budget_max' => 200000,
            'deadline_date' => '2026-11-25',
        ]);
    }

    private function offer(ServiceRequest $request, User $seller): PriceOffer
    {
        return PriceOffer::query()->forceCreate([
            'service_request_id' => $request->id,
            'seller_id' => $seller->id,
            'offered_price' => 150000,
            'max_revisions' => 2,
            'proposal_note' => 'Satu desain utama dan dua revisi sesuai brief permintaan.',
        ]);
    }

    private function directInput(Gig $gig, array $overrides = []): array
    {
        return [
            'gig_version' => $gig->version,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'creation_key' => (string) Str::uuid(),
            'buyer_brief' => self::BRIEF,
            ...$overrides,
        ];
    }
}
