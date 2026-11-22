<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\Gig;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\PaymentProof;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\PostgresTestCase;

class OrderPagesTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_order_tabs_and_seller_validation_queue_are_scoped_by_participant_and_stage(): void
    {
        $buyer = $this->student('buyer');
        $seller = $this->student('seller');
        $this->actingAs($buyer)->get('/pesanan')->assertOk()->assertSeeText('Pembelian Saya');
        $this->get('/pesanan?tab=riwayat')->assertOk()->assertSeeText('Riwayat');

        $pending = $seller->sellerOrders()->where('status', OrderStatus::AwaitingValidation)->firstOrFail();
        $other = $seller->sellerOrders()->where('status', OrderStatus::InProgress)->firstOrFail();

        $this->actingAs($seller)->get('/pesanan?tab=masuk&status=awaiting_validation')->assertOk()
            ->assertSee(route('orders.show', $pending))->assertDontSee(route('orders.show', $other));
        $this->get('/studio')->assertOk()->assertSeeText('Antrean & Validasi')
            ->assertSee(route('orders.show', $pending))->assertDontSee(route('orders.show', $other));
    }

    public function test_only_participants_and_pending_case_handler_can_view_order_detail(): void
    {
        $case = Report::whereNotNull('order_id')->where('status', 'pending')->firstOrFail();
        $order = $case->order;
        $url = '/pesanan/'.$order->id;
        $outsider = User::query()->whereNotIn('id', [$order->buyer_id, $order->seller_id])->firstOrFail();

        $this->get($url)->assertRedirect('/login');
        $this->actingAs($outsider)->get($url)->assertForbidden();
        $this->actingAs($order->buyer)->get($url)->assertOk()->assertDontSeeText('Simulasi demo');
        $this->actingAs($order->seller)->get($url.'/pembayaran')->assertOk()->assertDontSeeText('Simulasi demo');

        auth('web')->logout();
        $this->actingAs($case->handledByAdmin, 'admin')->get($url)->assertOk();
        $unrelated = Admin::query()->forceCreate([
            'name' => 'Admin Uji',
            'email' => 'admin.pesanan@example.test',
            'password_hash' => 'bukan-sandi-produksi',
        ]);
        $this->actingAs($unrelated, 'admin')->get($url)->assertForbidden();
    }

    public function test_checkout_explains_brief_and_asset_delivery_and_payment_pages_hide_demo_banners(): void
    {
        $buyer = $this->student('buyer');
        $gig = Gig::publiclyVisible()->where('seller_id', '!=', $buyer->id)->firstOrFail();
        $this->actingAs($buyer)->get(route('checkout.gig', $gig))->assertOk()
            ->assertSeeText('tema, ukuran, teks wajib, dan gaya warna')
            ->assertSeeText('Chat Pesanan setelah checkout')
            ->assertDontSeeText('Simulasi demo');

        $qris = $buyer->buyerOrders()->whereNotNull('qris_image_path_snapshot')->firstOrFail();
        $this->get(route('orders.payment.qris', $qris))->assertOk()->assertDontSeeText('Simulasi demo');
        $refund = $buyer->buyerOrders()->whereHas('payment', fn ($query) => $query->where('refund_status', 'pending'))->firstOrFail();
        $this->get(route('orders.payment.show', $refund))->assertOk()
            ->assertSeeText('Refund Penuh')->assertDontSeeText('Simulasi demo');
    }

    public function test_seller_payment_actions_use_separate_dialogs_and_amounts_still_require_an_integer(): void
    {
        $seller = $this->student('seller');
        $order = $seller->sellerOrders()->where('status', OrderStatus::AwaitingValidation)->firstOrFail();
        $proof = $order->payment->proofs()->where('seller_review_status', 'pending')->sole();

        $this->actingAs($seller)->get(route('orders.payment.show', $order))->assertOk()
            ->assertSee('data-currency-display', false)
            ->assertSee('aria-label="Konfirmasi Validasi Pembayaran"', false)
            ->assertSee('aria-label="Tolak Bukti Pembayaran"', false)
            ->assertSeeText($proof->original_filename)
            ->assertSeeText('Ya, Tolak Bukti');

        $this->postJson(route('orders.payment.proofs.verify', [$order, $proof]), [
            'amount' => $order->payment->amount.'.5', 'confirmed_recipient' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        self::assertSame(OrderStatus::AwaitingValidation, $order->refresh()->status);
    }

    public function test_private_proof_delivery_and_qris_snapshot_resolve_through_the_order_policy(): void
    {
        $proof = PaymentProof::query()->with('payment.order')->firstOrFail();
        $delivery = OrderDelivery::query()->whereNotNull('file_path')->with('order')->firstOrFail();
        $qrisOrder = Order::query()->whereNotNull('qris_image_path_snapshot')->firstOrFail();
        $disk = Storage::disk('private');

        foreach ([
            ['payment_proof', $proof->id, $proof->payment->order, $proof->file_path],
            ['order_delivery', $delivery->id, $delivery->order, $delivery->file_path],
            ['order_qris_snapshot', $qrisOrder->id, $qrisOrder, $qrisOrder->qris_image_path_snapshot],
        ] as [$kind, $id, $order, $path]) {
            $url = "/berkas/{$kind}/{$id}";
            $outsider = User::whereNotIn('id', [$order->buyer_id, $order->seller_id])->firstOrFail();
            $this->actingAs($outsider)->get($url)->assertForbidden();
            $response = $this->actingAs($order->buyer)->get($url)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
            self::assertSame($disk->get($path), $response->streamedContent());
        }
    }

    public function test_public_seller_profile_only_displays_visible_reviews(): void
    {
        $seller = $this->student('seller');
        $reviews = $seller->receivedReviews();
        $visible = (clone $reviews)->visible()->whereNotNull('comment')->firstOrFail();
        $hidden = (clone $reviews)->where('is_hidden', true)->whereNotNull('comment')->first();
        $response = $this->get('/penjual/'.$seller->id)->assertOk()->assertSeeText($seller->name)
            ->assertSeeText($visible->comment)->assertDontSeeText($seller->email);
        if ($hidden) {
            $response->assertDontSeeText($hidden->comment);
        }
    }

    public function test_refund_chat_entry_point_remains_order_scoped_for_existing_participants(): void
    {
        $order = Order::whereHas('payment', fn ($query) => $query->where('refund_status', 'pending'))->firstOrFail();
        $outsider = User::whereNotIn('id', [$order->buyer_id, $order->seller_id])->firstOrFail();
        $url = "/pesanan/{$order->id}/chat";

        $this->actingAs($outsider)->get($url)->assertForbidden();
        $seller = $order->seller;
        $seller->forceFill(['is_suspended' => true])->saveOrFail();
        $this->actingAs($seller)->get($url)->assertOk()->assertSeeText('Chat Pesanan')
            ->assertSee(route('orders.show', $order));
    }

    private function student(string $key): User
    {
        return User::where('email', $key.'.demo@apps.ipb.ac.id')->sole();
    }
}
