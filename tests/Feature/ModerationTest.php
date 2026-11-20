<?php

namespace Tests\Feature;

use App\Enums\GigStatus;
use App\Enums\OrderStatus;
use App\Enums\PriceOfferStatus;
use App\Enums\RefundStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\ServiceRequestStatus;
use App\Livewire\Admin\Moderation\Index;
use App\Models\Admin;
use App\Models\Gig;
use App\Models\Order;
use App\Models\PriceOffer;
use App\Models\Report;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ModerationService;
use App\Services\Orders\OfferAcceptanceService;
use App\Services\ReportService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\PostgresTestCase;

class ModerationTest extends PostgresTestCase
{
    private const REASON = 'Materi melanggar pedoman komunitas yang diperiksa Admin.';

    public function test_gig_suspension_hides_contents_and_lifting_suspension_returns_to_inactive(): void
    {
        $this->withoutVite();
        $gig = Gig::publiclyVisible()->firstOrFail();
        $admin = Admin::query()->firstOrFail();
        $service = app(ModerationService::class);

        $service->apply($admin, 'suspend_gig', $gig->id, self::REASON);
        self::assertSame(GigStatus::Suspended, $gig->refresh()->status);
        self::assertTrue(Gate::forUser($gig->seller)->allows('update', $gig));
        self::assertFalse(Gate::forUser($gig->seller)->allows('changeStatus', [$gig, GigStatus::Active]));
        $this->get(route('catalog.show', $gig))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', [
            'actor_admin_id' => $admin->id, 'entity_type' => 'gigs', 'entity_id' => $gig->id,
            'action' => 'gig.suspended', 'reason' => self::REASON,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $gig->seller_id, 'type' => 'gig.suspended', 'body' => self::REASON,
        ]);

        $service->apply($admin, 'unsuspend_gig', $gig->id, self::REASON);
        self::assertSame(GigStatus::Inactive, $gig->refresh()->status);
        $this->get(route('catalog.show', $gig))->assertNotFound();
    }

    public function test_admin_closes_open_request_and_rejects_pending_offers_but_only_hides_ordered_request(): void
    {
        $this->withoutVite();
        $offer = PriceOffer::query()->where('status', PriceOfferStatus::Pending)->firstOrFail();
        $request = $offer->serviceRequest;
        $admin = Admin::query()->firstOrFail();
        $service = app(ModerationService::class);

        $service->apply($admin, 'close_request', $request->id, self::REASON);
        self::assertSame(ServiceRequestStatus::Closed, $request->refresh()->status);
        self::assertSame(PriceOfferStatus::Rejected, $offer->refresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'actor_admin_id' => $admin->id, 'entity_type' => 'price_offers', 'entity_id' => $offer->id,
            'action' => 'offer.rejected', 'reason' => self::REASON,
        ]);
        $this->get(route('requests.show', $request))->assertNotFound();

        $ordered = ServiceRequest::query()->where('status', ServiceRequestStatus::Ordered)->firstOrFail();
        $orderedOffer = $ordered->priceOffers()->where('status', PriceOfferStatus::Accepted)->firstOrFail();
        $order = $orderedOffer->order;
        $before = $order->getAttributes();
        $service->apply($admin, 'close_request', $ordered->id, self::REASON);
        self::assertTrue($ordered->refresh()->is_hidden);
        self::assertSame(ServiceRequestStatus::Ordered, $ordered->status);
        self::assertSame(PriceOfferStatus::Accepted, $orderedOffer->refresh()->status);
        self::assertSame($before, $order->refresh()->getAttributes());
    }

    public function test_hiding_review_keeps_original_content_and_updates_public_seller_rating(): void
    {
        $review = Review::visible()->firstOrFail();
        $admin = Admin::query()->firstOrFail();
        $original = $review->getAttributes();
        $remainingRating = Review::visible()->where('seller_id', $review->seller_id)->whereKeyNot($review->id)->avg('rating');
        $gigQuery = Gig::where('seller_id', $review->seller_id)->withSellerRating();

        $moderation = app(ModerationService::class);
        try {
            $moderation->apply($admin, 'hide_review', $review->id, self::REASON);
            self::fail('A review was hidden outside of an assigned case.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        $reports = app(ReportService::class);
        $case = $reports->submit($review->seller, ReportType::ReviewReport, $review->id, [
            'category' => 'copyright_privacy', 'description' => 'Ulasan ini memuat informasi yang perlu diperiksa Admin.',
        ]);
        $this->actingAs($admin, 'admin')->get(route('admin.moderation.index', ['jenis' => 'review']))
            ->assertOk()->assertSee(route('admin.reports.show', $case));
        $reports->claim($admin, $case);
        $moderation->apply($admin, 'hide_review', $review->id, self::REASON, $case);
        $review->refresh();
        self::assertTrue($review->is_hidden);
        self::assertSame($admin->id, $review->hidden_by_admin_id);
        self::assertSame(self::REASON, $review->hide_reason);
        self::assertNotNull($review->hidden_at);
        foreach (['reviewer_id', 'seller_id', 'rating', 'comment', 'review_date'] as $field) {
            self::assertSame($original[$field], $review->getRawOriginal($field));
        }
        self::assertSame($remainingRating, (clone $gigQuery)->firstOrFail()->seller_rating);
    }

    public function test_hiding_open_request_halts_new_transactions_without_rejecting_existing_offers(): void
    {
        $offer = PriceOffer::query()->where('status', PriceOfferStatus::Pending)->firstOrFail();
        $request = $offer->serviceRequest;
        $buyer = $request->buyer;
        $acceptance = app(OfferAcceptanceService::class);
        self::assertTrue($acceptance->canAccept($buyer, $offer));

        app(ModerationService::class)->apply(Admin::query()->firstOrFail(), 'hide_request', $request->id, self::REASON);

        self::assertTrue($request->refresh()->is_hidden);
        self::assertSame(ServiceRequestStatus::Open, $request->status);
        self::assertSame(PriceOfferStatus::Pending, $offer->refresh()->status);
        self::assertFalse($acceptance->canAccept($buyer, $offer));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id, 'type' => 'request.hidden', 'body' => self::REASON,
        ]);
    }

    public function test_account_suspension_hides_public_material_without_rewriting_it_and_keeps_existing_order_access(): void
    {
        $this->withoutVite();
        $gig = Gig::publiclyVisible()->firstOrFail();
        $order = Order::query()->where('seller_id', $gig->seller_id)->firstOrFail();
        $seller = $gig->seller;
        $buyer = $order->buyer;
        $request = ServiceRequest::publiclyVisible()->where('buyer_id', $buyer->id)->firstOrFail();
        $admin = Admin::query()->firstOrFail();
        $service = app(ModerationService::class);

        $service->apply($admin, 'suspend_user', $seller->id, self::REASON);
        self::assertTrue($seller->refresh()->is_suspended);
        self::assertSame(GigStatus::Active, $gig->refresh()->status);
        $this->get(route('catalog.show', $gig))->assertNotFound();
        $this->actingAs($seller)->post(route('studio.gigs.store'))->assertForbidden();
        $this->post(route('offers.store', $request))->assertForbidden();
        $this->get(route('orders.show', $order))->assertOk();
        $this->get(route('chat.show', $order->chatRoom))->assertOk();
        $this->actingAs($buyer)->post(route('chat.gig', $gig))->assertForbidden();
        $this->get(route('checkout.gig', $gig))->assertForbidden();

        $service->apply($admin, 'suspend_user', $buyer->id, self::REASON);
        self::assertFalse(ServiceRequest::publiclyVisible()->whereKey($request->id)->exists());
        self::assertSame(ServiceRequestStatus::Open, $request->refresh()->status);
        self::assertFalse($request->is_hidden);
        $this->actingAs($buyer->refresh())->post(route('requests.store'))->assertForbidden();
        $this->get(route('orders.show', $order))->assertOk();
        $refundableOrder = $buyer->buyerOrders()->where('status', OrderStatus::Cancelled)
            ->whereHas('payment', fn ($payment) => $payment->where('refund_status', RefundStatus::Pending))->firstOrFail();
        $this->post(route('orders.refund.confirm', $refundableOrder), ['confirmed' => true])->assertRedirect();
        self::assertSame(RefundStatus::Confirmed, $refundableOrder->payment->refund_status);
        self::assertTrue($refundableOrder->chatRoom->isArchived());

        $service->apply($admin, 'unsuspend_user', $buyer->id, self::REASON);
        $service->apply($admin, 'unsuspend_user', $seller->id, self::REASON);
        self::assertTrue(ServiceRequest::publiclyVisible()->whereKey($request->id)->exists());
        self::assertTrue(Gig::publiclyVisible()->whereKey($gig->id)->exists());
    }

    public function test_case_moderation_is_limited_to_the_assigned_admin_and_links_to_case_audit(): void
    {
        $report = Report::query()->where('status', ReportStatus::Pending)->whereNotNull('order_id')
            ->whereNotNull('handled_by_admin_id')->firstOrFail();
        $unassigned = Admin::query()->forceCreate([
            'name' => 'Admin Kedua Moderasi', 'email' => 'admin-second-moderation@example.test',
            'password_hash' => 'Password demo test',
        ]);
        $gig = $report->order->gig;
        $moderation = app(ModerationService::class);

        try {
            $moderation->apply($unassigned, 'suspend_gig', $gig->id, self::REASON, $report);
            self::fail('A different admin acted on the case.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(GigStatus::Active, $gig->refresh()->status);

        $moderation->apply($report->handledByAdmin, 'suspend_gig', $gig->id, self::REASON, $report);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'gig.suspended', 'entity_type' => 'gigs', 'entity_id' => $gig->id,
            'actor_admin_id' => $report->handled_by_admin_id, 'report_id' => $report->id, 'order_id' => $report->order_id,
        ]);
    }

    public function test_moderation_requires_a_reason_and_admin_guard_on_livewire_actions(): void
    {
        $this->withoutVite();
        $admin = Admin::query()->firstOrFail();
        $gig = Gig::publiclyVisible()->firstOrFail();
        try {
            app(ModerationService::class)->apply($admin, 'suspend_gig', $gig->id, "\u{00A0} \n");
            self::fail('A blank reason was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors());
        }

        $this->actingAs(User::query()->findOrFail($gig->seller_id));
        $this->get(route('admin.moderation.index'))->assertRedirect(route('login'));
        Livewire::test(Index::class)->assertForbidden();
        $this->actingAs($admin, 'admin');
        $this->get(route('admin.moderation.index'))->assertOk()->assertSee('Moderasi');
        Livewire::test(Index::class)
            ->set('search', (string) $gig->id)
            ->call('openAction', $gig->id)
            ->set('action', 'suspend_user')
            ->set('reason', self::REASON)
            ->call('apply')
            ->assertHasErrors('action')
            ->set('action', 'suspend_gig')
            ->call('apply')
            ->assertHasNoErrors()
            ->assertSee('Tindakan moderasi tersimpan.');
    }
}
