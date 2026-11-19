<?php

namespace Tests\Feature;

use App\Enums\GigStatus;
use App\Enums\PriceOfferStatus;
use App\Models\Category;
use App\Models\Gig;
use App\Models\ServiceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\PostgresTestCase;

class MarketplaceTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 17:30:00', 'UTC'));
    }

    public function test_catalog_applies_eligibility_and_exact_filter_boundaries(): void
    {
        $seller = $this->student('seller');
        $category = $this->category();
        $otherCategory = Category::create(['name' => 'Kategori Marketplace Pembanding']);

        $this->gig($seller, $category, ['base_price' => 99999]);
        $lower = $this->gig($seller, $category, ['base_price' => 100000]);
        $upper = $this->gig($seller, $category, ['base_price' => 250000]);
        $this->gig($seller, $category, ['base_price' => 250001]);
        $this->gig($seller, $category, ['base_price' => 150000, 'delivery_days' => 4]);
        $this->gig($seller, $otherCategory);
        $inactive = $this->gig($seller, $category, ['status' => GigStatus::Inactive]);
        $suspended = $this->gig($this->student('suspended'), $category);
        $withoutPayment = $this->gig($this->student('buyer'), $category);

        $response = $this->get('/jasa?'.http_build_query([
            'q' => 'desainer demo',
            'category' => $category->id,
            'price' => '100k_250k',
            'duration' => 3,
            'sort' => 'price_desc',
        ]))->assertOk();

        self::assertSame([$upper->id, $lower->id], $response->viewData('gigs')->getCollection()->modelKeys());
        $this->get('/jasa/'.$lower->id)->assertOk();
        $this->get('/jasa/'.$inactive->id)->assertNotFound();
        $this->get('/jasa/'.$suspended->id)->assertNotFound();
        $this->get('/jasa/'.$withoutPayment->id)->assertNotFound();

        $category->forceFill(['is_active' => false])->save();
        $this->get('/jasa/'.$lower->id)->assertNotFound();
    }

    public function test_rating_uses_visible_seller_reviews_and_leaves_unrated_gigs_last(): void
    {
        $category = $this->category();
        $rated = $this->gig($this->student('seller'), $category);
        $unrated = $this->gig($this->student('seller2'), $category);

        $gigs = $this->get('/jasa?category='.$category->id.'&sort=rating')
            ->assertOk()->viewData('gigs')->getCollection();

        self::assertSame([$rated->id, $unrated->id], $gigs->modelKeys());
        self::assertSame(5.0, (float) $gigs->first()->seller_rating);
        self::assertNull($gigs->last()->seller_rating);

        $this->actingAs($this->student('seller'))->get('/jasa/'.$rated->id)
            ->assertOk()->assertViewHas('canOrder', false);
    }

    public function test_gig_creation_requires_a_verified_seller_with_payment_details(): void
    {
        $payload = $this->gigInput($this->category());

        foreach (['unverified', 'suspended', 'buyer'] as $account) {
            $this->actingAs($this->student($account))->post('/studio/jasa', $payload)->assertForbidden();
        }

        $this->actingAs($this->student('seller'))->post('/studio/jasa', $payload)->assertRedirect();
        $gig = Gig::where('title', $payload['title'])->sole();
        self::assertSame(GigStatus::Active, $gig->status);
        self::assertSame(1, $gig->version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'gig.created', 'entity_id' => $gig->id]);
    }

    public function test_gig_portfolio_validates_type_and_size_before_publication(): void
    {
        Storage::fake('public');
        $this->actingAs($this->student('seller'));
        $payload = $this->gigInput($this->category());

        $this->post('/studio/jasa', [...$payload, 'portfolio' => UploadedFile::fake()->create('gambar.png', 5121, 'image/png')])
            ->assertSessionHasErrors('portfolio');
        $this->post('/studio/jasa', [...$payload, 'portfolio' => UploadedFile::fake()->create('bahan.pdf', 20481, 'application/pdf')])
            ->assertSessionHasErrors('portfolio');
        $this->post('/studio/jasa', [...$payload, 'portfolio' => UploadedFile::fake()->create('data.zip', 10, 'application/zip')])
            ->assertSessionHasErrors('portfolio');
        $this->assertDatabaseMissing('gigs', ['title' => $payload['title']]);

        $this->post('/studio/jasa', [...$payload, 'portfolio' => UploadedFile::fake()->create('bahan.pdf', 20480, 'application/pdf')])
            ->assertRedirect();
        $gig = Gig::where('title', $payload['title'])->sole();
        Storage::disk('public')->assertExists($gig->portfolio_path);
        $this->get('/jasa/'.$gig->id.'/portofolio')->assertOk()->assertDownload();
    }

    public function test_gig_owner_can_edit_suspended_gigs_and_only_toggle_non_suspended_gigs(): void
    {
        $seller = $this->student('seller');
        $category = $this->category();
        $active = $this->gig($seller, $category);
        $suspended = $this->gig($seller, $category, ['status' => GigStatus::Suspended]);

        $this->actingAs($this->student('seller2'))->patch('/studio/jasa/'.$suspended->id, $this->gigInput($category))
            ->assertForbidden();
        $this->actingAs($seller)->patch('/studio/jasa/'.$suspended->id, [
            ...$this->gigInput($category),
            'title' => 'Judul Gig setelah diperbaiki',
        ])->assertRedirect();
        self::assertSame(GigStatus::Suspended, $suspended->refresh()->status);
        self::assertSame(2, $suspended->version);
        $this->post('/studio/jasa/'.$suspended->id.'/status', ['status' => 'active'])->assertForbidden();

        $this->post('/studio/jasa/'.$active->id.'/status', ['status' => 'inactive'])->assertRedirect();
        self::assertSame(GigStatus::Inactive, $active->refresh()->status);
        $this->post('/studio/jasa/'.$active->id.'/status', ['status' => 'inactive'])->assertRedirect();
        self::assertSame(GigStatus::Inactive, $active->refresh()->status);
        $this->post('/studio/jasa/'.$active->id.'/status', ['status' => 'active'])->assertRedirect();
        self::assertSame(GigStatus::Active, $active->refresh()->status);
    }

    public function test_board_filters_in_wib_and_excludes_unavailable_requests(): void
    {
        $buyer = $this->student('buyer');
        $category = $this->category();
        $available = $this->serviceRequest($buyer, $category, ['deadline_date' => '2026-11-20']);
        $expired = $this->serviceRequest($buyer, $category, ['deadline_date' => '2026-11-19']);
        $hidden = $this->serviceRequest($buyer, $category, ['is_hidden' => true]);
        $closed = $this->serviceRequest($buyer, $category, ['status' => 'closed']);
        $this->serviceRequest($this->student('suspended'), $category);

        $visible = $this->get('/permintaan?q=MARKETPLACE&category='.$category->id)
            ->assertOk()->viewData('requests')->getCollection();
        self::assertSame([$available->id], $visible->modelKeys());
        foreach ([$expired, $hidden, $closed] as $request) {
            $this->get('/permintaan/'.$request->id)->assertNotFound();
        }
    }

    public function test_verified_buyer_can_create_and_edit_request_only_before_any_offer(): void
    {
        $buyer = $this->student('buyer');
        $payload = $this->requestInput($this->category());
        $this->actingAs($this->student('unverified'))->post('/permintaan', $payload)->assertForbidden();
        $this->actingAs($buyer)->post('/permintaan', $payload)->assertRedirect();
        $request = ServiceRequest::where('title', $payload['title'])->sole();

        $this->actingAs($this->student('seller'))->patch('/permintaan/'.$request->id, $payload)
            ->assertForbidden();
        $this->actingAs($buyer)->patch('/permintaan/'.$request->id, [...$payload, 'title' => 'Perubahan brief pertama'])
            ->assertRedirect();
        self::assertSame(2, $request->refresh()->version);

        $this->actingAs($this->student('seller'))->post('/permintaan/'.$request->id.'/penawaran', $this->offerInput($request))
            ->assertRedirect();
        $offer = $request->priceOffers()->sole();
        $this->post('/penawaran/'.$offer->id.'/tarik', [
            'request_version' => $request->version, 'offer_version' => $offer->version,
        ])->assertRedirect();

        $this->actingAs($buyer)->patch('/permintaan/'.$request->id, [...$payload, 'title' => 'Perubahan yang ditolak'])
            ->assertForbidden();
        self::assertSame('Perubahan brief pertama', $request->refresh()->title);
    }

    public function test_offer_is_private_and_a_withdrawn_offer_permanently_prevents_resubmission(): void
    {
        $seller = $this->student('seller');
        $buyer = $this->student('buyer');
        $request = $this->serviceRequest($buyer, $this->category());
        $payload = $this->offerInput($request);
        $path = '/permintaan/'.$request->id;
        $proposal = 'Proposal privat dengan rencana pengerjaan khusus.';

        $this->actingAs($seller)->post($path.'/penawaran', [...$payload, 'proposal_note' => '  '.$proposal.'  '])
            ->assertRedirect();
        $offer = $request->priceOffers()->sole();
        self::assertSame($proposal, $offer->proposal_note);
        self::assertSame(100000, $offer->offered_price);
        $this->get($path)->assertOk()->assertSee($proposal);
        $this->actingAs($buyer)->get($path)->assertOk()->assertSee($proposal);
        $this->actingAs($this->student('seller2'))->get($path)->assertOk()->assertDontSee($proposal);
        $this->patch('/penawaran/'.$offer->id, [...$payload, 'offer_version' => 1])->assertForbidden();

        $this->actingAs($seller)->patch('/penawaran/'.$offer->id, [...$payload, 'offer_version' => 1, 'offered_price' => 125000])
            ->assertRedirect();
        self::assertSame(125000, $offer->refresh()->offered_price);
        self::assertSame(2, $offer->version);
        $this->post('/penawaran/'.$offer->id.'/tarik', [
            'request_version' => $request->version, 'offer_version' => 1,
        ])->assertConflict();
        self::assertSame(PriceOfferStatus::Pending, $offer->refresh()->status);
        $this->post('/penawaran/'.$offer->id.'/tarik', [
            'request_version' => $request->version, 'offer_version' => $offer->version,
        ])->assertRedirect();
        self::assertSame(PriceOfferStatus::Withdrawn, $offer->refresh()->status);
        $this->post($path.'/penawaran', $payload)->assertSessionHasErrors('offered_price');
        self::assertSame(1, $request->priceOffers()->count());
    }

    public function test_admin_can_review_private_offers_without_acting_as_a_student(): void
    {
        $seller = $this->student('seller');
        $request = $this->serviceRequest($this->student('buyer'), $this->category());
        $proposal = 'Rencana pengerjaan khusus untuk pembeli yang meminta.';
        $this->actingAs($seller, 'web')->post('/permintaan/'.$request->id.'/penawaran', [
            ...$this->offerInput($request),
            'proposal_note' => $proposal,
        ])->assertRedirect();
        auth('web')->logout();

        $admin = \App\Models\Admin::where('email', 'admin@gigkampus.test')->sole();
        $this->actingAs($admin, 'admin')->get('/permintaan/'.$request->id)->assertOk()->assertSee($proposal);
        $this->post('/permintaan/'.$request->id.'/tutup')->assertRedirect('/login');
    }

    public function test_offers_reject_self_ineligible_accounts_unavailable_requests_and_prices_above_budget(): void
    {
        $seller = $this->student('seller');
        $buyer = $this->student('buyer');
        $category = $this->category();
        $request = $this->serviceRequest($buyer, $category);
        $own = $this->serviceRequest($seller, $category);
        $other = $this->serviceRequest($this->student('seller2'), $category);
        $closed = $this->serviceRequest($buyer, $category, ['status' => 'closed']);
        $ordered = $this->serviceRequest($buyer, $category, ['status' => 'ordered']);
        $expired = $this->serviceRequest($buyer, $category, ['deadline_date' => '2026-11-19']);
        $hidden = $this->serviceRequest($buyer, $category, ['is_hidden' => true]);
        $suspended = $this->serviceRequest($this->student('suspended'), $category);

        foreach (['unverified', 'suspended', 'buyer'] as $account) {
            $this->actingAs($this->student($account))->post('/permintaan/'.$other->id.'/penawaran', $this->offerInput($other))
                ->assertForbidden();
        }

        $this->actingAs($seller);
        foreach ([$own, $closed, $ordered, $expired, $hidden, $suspended] as $unavailable) {
            $this->post('/permintaan/'.$unavailable->id.'/penawaran', $this->offerInput($unavailable))->assertForbidden();
            self::assertFalse($unavailable->priceOffers()->exists());
        }
        $this->post('/permintaan/'.$request->id.'/penawaran', [...$this->offerInput($request), 'offered_price' => 200001])
            ->assertSessionHasErrors('offered_price');
        self::assertFalse($request->priceOffers()->exists());
    }

    public function test_stale_offer_response_preserves_only_safe_fields_and_does_not_insert(): void
    {
        $request = $this->serviceRequest($this->student('buyer'), $this->category());
        $request->forceFill(['budget_max' => 250000])->save();
        $proposal = '  Proposal dengan teks <script>alert(1)</script>.  ';

        $response = $this->actingAs($this->student('seller'))
            ->post('/permintaan/'.$request->id.'/penawaran', [
                ...$this->offerInput($request),
                'request_version' => 1,
                'proposal_note' => $proposal,
                'private_payment_secret' => 'SENSITIVE-INPUT-EXAMPLE',
            ])->assertConflict()->assertDontSee('SENSITIVE-INPUT-EXAMPLE')->assertDontSee('<script>alert(1)</script>', false);

        self::assertSame(trim($proposal), $response->viewData('retained')['proposal_note']);
        self::assertSame(2, $response->viewData('serviceRequest')->version);
        self::assertFalse($request->priceOffers()->exists());
    }

    public function test_an_open_editing_form_keeps_its_draft_after_the_buyer_closes_the_request(): void
    {
        $buyer = $this->student('buyer');
        $seller = $this->student('seller');
        $request = $this->serviceRequest($buyer, $this->category());

        $this->actingAs($seller)->post('/permintaan/'.$request->id.'/penawaran', $this->offerInput($request))
            ->assertRedirect();
        $offer = $request->priceOffers()->sole();
        $this->actingAs($buyer)->post('/permintaan/'.$request->id.'/tutup')->assertRedirect();

        $draft = 'Draf catatan yang sedang ditulis pada saat permintaan ditutup.';
        $this->actingAs($seller)->patch('/penawaran/'.$offer->id, [
            ...$this->offerInput($request),
            'offer_version' => $offer->version,
            'proposal_note' => $draft,
        ])->assertConflict()->assertSee($draft)->assertSee('Ditutup');

        self::assertSame(PriceOfferStatus::Rejected, $offer->refresh()->status);
    }

    public function test_closing_request_rejects_only_pending_offers_and_records_audit_and_inbox_entries(): void
    {
        $buyer = $this->student('buyer');
        $request = $this->serviceRequest($buyer, $this->category());

        foreach (['seller', 'seller2'] as $account) {
            $this->actingAs($this->student($account))->post('/permintaan/'.$request->id.'/penawaran', $this->offerInput($request))
                ->assertRedirect();
        }

        $offers = $request->priceOffers()->get()->keyBy('seller_id');
        $this->actingAs($this->student('seller'))->post('/permintaan/'.$request->id.'/tutup')->assertForbidden();
        $this->actingAs($buyer)->post('/permintaan/'.$request->id.'/tutup')->assertRedirect();
        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'status' => 'closed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request.closed', 'entity_id' => $request->id]);

        foreach ($offers as $offer) {
            $offer->refresh();
            self::assertSame(PriceOfferStatus::Rejected, $offer->status);
            self::assertNotNull($offer->status_reason);
            $this->assertDatabaseHas('audit_logs', ['action' => 'offer.rejected', 'entity_id' => $offer->id]);
            $this->assertDatabaseHas('notifications', ['type' => 'offer.rejected', 'user_id' => $offer->seller_id]);
        }

        $this->post('/permintaan/'.$request->id.'/tutup')->assertForbidden();
    }

    private function student(string $key): User
    {
        return User::where('email', $key.'.demo@apps.ipb.ac.id')->sole();
    }

    private function category(): Category
    {
        return Category::create(['name' => 'Kategori Uji Marketplace']);
    }

    private function gig(User $seller, Category $category, array $attributes = []): Gig
    {
        $gig = new Gig;
        $gig->forceFill([...$this->gigInput($category), 'seller_id' => $seller->id, ...$attributes])->save();

        return $gig;
    }

    private function gigInput(Category $category): array
    {
        return [
            'title' => 'Pembuatan Aset Marketplace',
            'description' => 'Pembuatan aset visual yang dirancang untuk mahasiswa.',
            'base_price' => 150000,
            'delivery_days' => 3,
            'max_revisions' => 2,
            'category_id' => $category->id,
        ];
    }

    private function serviceRequest(User $buyer, Category $category, array $attributes = []): ServiceRequest
    {
        $request = new ServiceRequest;
        $request->forceFill([...$this->requestInput($category), 'buyer_id' => $buyer->id, ...$attributes])->save();

        return $request;
    }

    private function requestInput(Category $category): array
    {
        return [
            'title' => 'Butuh Bantuan Marketplace',
            'description' => 'Membutuhkan bantuan membuat poster dari bahan yang disediakan.',
            'category_id' => $category->id,
            'budget_min' => 150000,
            'budget_max' => 200000,
            'deadline_date' => '2026-11-25',
        ];
    }

    private function offerInput(ServiceRequest $request): array
    {
        return [
            'offered_price' => 100000,
            'max_revisions' => 2,
            'proposal_note' => 'Saya dapat membantu menyiapkan semua desain sesuai brief.',
            'request_version' => $request->version,
        ];
    }
}
