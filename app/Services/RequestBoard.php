<?php

namespace App\Services;

use App\Enums\PriceOfferStatus;
use App\Models\Category;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RequestBoard
{
    public function save(User $buyer, array $terms, ?ServiceRequest $existing = null): ServiceRequest
    {
        return DB::transaction(function () use ($buyer, $terms, $existing): ServiceRequest {
            $buyer = User::query()->lock('for no key update')->findOrFail($buyer->id);
            $request = $existing === null
                ? new ServiceRequest
                : ServiceRequest::query()->lockForUpdate()->findOrFail($existing->id);
            Gate::forUser($buyer)->authorize($request->exists ? 'update' : 'create', $request->exists ? $request : ServiceRequest::class);

            if (! Category::active()->sharedLock()->find($terms['category_id'])) {
                throw ValidationException::withMessages(['category_id' => 'Pilih kategori yang masih aktif.']);
            }
            if ($terms['deadline_date'] < now('Asia/Jakarta')->toDateString()) {
                throw ValidationException::withMessages(['deadline_date' => 'Tenggat harus hari ini atau setelahnya (WIB).']);
            }

            $before = $request->exists ? $request->getAttributes() : [];
            $request->fill($terms)->forceFill(['category_id' => (int) $terms['category_id'], 'buyer_id' => $buyer->id])->save();
            if ($request->wasRecentlyCreated || $request->wasChanged()) {
                $action = $request->wasRecentlyCreated ? 'request.created' : 'request.updated';
                Audit::record($buyer, $action, $request, $before, $request->getAttributes());
            }

            return $request;
        });
    }

    public function close(User $buyer, ServiceRequest $existing): void
    {
        DB::transaction(function () use ($buyer, $existing): void {
            $buyer = User::query()->lock('for no key update')->findOrFail($buyer->id);
            $request = ServiceRequest::query()->lockForUpdate()->findOrFail($existing->id);
            Gate::forUser($buyer)->authorize('close', $request);
            $before = $request->only(['status']);
            $request->close();
            $request->save();
            Audit::record($buyer, 'request.closed', $request, $before, $request->only(['status']));

            $pendingOffers = $request->priceOffers()->where('status', PriceOfferStatus::Pending)
                ->orderBy('id')->lockForUpdate()->get();
            $reason = 'Pemilik menutup permintaan.';

            foreach ($pendingOffers as $offer) {
                $before = $offer->only(['status', 'version']);
                $offer->reject($reason);
                $offer->save();
                Audit::record($buyer, 'offer.rejected', $offer, $before, $offer->only(['status', 'version']), $reason);
                Inbox::offer($offer->seller_id, 'offer.rejected', 'Penawaran ditolak', $reason, $offer);
            }
        });
    }
}
