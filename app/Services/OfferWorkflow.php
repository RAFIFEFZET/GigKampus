<?php

namespace App\Services;

use App\Exceptions\StaleOffer;
use App\Models\Category;
use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class OfferWorkflow
{
    public function submit(User $seller, ServiceRequest $request, array $terms): void
    {
        $this->mutate($seller, $request, function (User $seller, ServiceRequest $request) use ($terms): void {
            $request->setRelation('category', Category::query()->sharedLock()->findOrFail($request->category_id));
            Gate::forUser($seller)->authorize('create', [PriceOffer::class, $request]);
            $this->checkRequestVersion($request, $terms);

            if ($request->priceOffers()->whereBelongsTo($seller, 'seller')->exists()) {
                throw ValidationException::withMessages(['offered_price' => 'Anda sudah pernah mengirim penawaran untuk permintaan ini.']);
            }
            $this->checkPrice($request, $terms);

            $offer = new PriceOffer($this->offerTerms($terms));
            $offer->seller_id = $seller->id;
            $request->priceOffers()->save($offer);

            Audit::record($seller, 'offer.submitted', $offer, [], $offer->getAttributes());
            Inbox::offer($request->buyer_id, 'offer.submitted', 'Penawaran baru', 'Ada penawaran baru untuk permintaan Anda.', $offer);
        });
    }

    public function update(User $seller, PriceOffer $existing, array $terms): void
    {
        $this->mutate($seller, $existing->serviceRequest, function (User $seller, ServiceRequest $request) use ($existing, $terms): void {
            $offer = $request->priceOffers()->lockForUpdate()->findOrFail($existing->id);
            Gate::forUser($seller)->authorize('manage', $offer);
            $this->checkVersions($request, $terms, $offer);
            Gate::forUser($seller)->authorize('update', [$offer, $request]);
            $this->checkPrice($request, $terms);

            $before = $offer->getAttributes();
            $offer->fill($this->offerTerms($terms));
            $offer->save();

            if ($offer->wasChanged()) {
                Audit::record($seller, 'offer.updated', $offer, $before, $offer->getAttributes());
                Inbox::offer($request->buyer_id, 'offer.updated', 'Penawaran diperbarui', 'Seller memperbarui penawaran untuk permintaan Anda.', $offer);
            }
        });
    }

    public function withdraw(User $seller, PriceOffer $existing, array $versions): void
    {
        $this->mutate($seller, $existing->serviceRequest, function (User $seller, ServiceRequest $request) use ($existing, $versions): void {
            $offer = $request->priceOffers()->lockForUpdate()->findOrFail($existing->id);
            Gate::forUser($seller)->authorize('manage', $offer);
            $this->checkVersions($request, $versions, $offer);
            Gate::forUser($seller)->authorize('withdraw', [$offer, $request]);
            $before = $offer->only(['status', 'version']);
            $reason = 'Seller menarik penawaran.';
            $offer->withdraw($seller, $request, $reason);
            $offer->save();

            Audit::record($seller, 'offer.withdrawn', $offer, $before, $offer->only(['status', 'version']), $reason);
            Inbox::offer($request->buyer_id, 'offer.withdrawn', 'Penawaran ditarik', $reason, $offer);
        });
    }

    private function mutate(User $seller, ServiceRequest $existing, Closure $action): void
    {
        DB::transaction(function () use ($seller, $existing, $action): void {
            // Account rows come first (ascending ID), then the request, then any offers.
            // NO KEY UPDATE serializes account changes and still permits notification foreign keys.
            $users = User::whereKey([$seller->id, $existing->buyer_id])
                ->orderBy('id')->lock('for no key update')->get()->keyBy('id');
            $request = ServiceRequest::query()->lockForUpdate()->findOrFail($existing->id);
            $request->setRelation('buyer', $users->get($request->buyer_id));
            $action($users->get($seller->id), $request);
        });
    }

    private function checkRequestVersion(ServiceRequest $request, array $terms): void
    {
        if ($request->version !== (int) $terms['request_version']) {
            throw new StaleOffer('Ketentuan permintaan telah berubah. Tinjau kembali brief dan anggaran sebelum mengirim penawaran.');
        }
    }

    private function checkPrice(ServiceRequest $request, array $terms): void
    {
        if ((int) $terms['offered_price'] > $request->budget_max) {
            throw ValidationException::withMessages(['offered_price' => 'Harga penawaran melebihi anggaran maksimum permintaan.']);
        }
    }

    private function checkVersions(ServiceRequest $request, array $terms, PriceOffer $offer): void
    {
        $this->checkRequestVersion($request, $terms);

        if ($offer->version !== (int) $terms['offer_version']) {
            throw new StaleOffer('Penawaran telah berubah. Tinjau versi terbaru sebelum mencoba kembali.');
        }
    }

    private function offerTerms(array $terms): array
    {
        $offer = Arr::only($terms, ['offered_price', 'max_revisions', 'proposal_note']);
        $offer['proposal_note'] = trim($offer['proposal_note']);

        return $offer;
    }
}
