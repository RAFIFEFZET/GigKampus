<?php

namespace App\Services;

use App\Enums\PriceOfferStatus;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Report;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

            $this->rejectPendingOffers($request, $buyer, 'Pemilik menutup permintaan.');
        });
    }

    /** Reject outstanding offers after the caller locks and closes the request in the same transaction. */
    public function rejectPendingOffers(ServiceRequest $request, User|Admin $actor, string $reason, ?Report $report = null): void
    {
        $pendingOffers = $request->priceOffers()->where('status', PriceOfferStatus::Pending)
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($pendingOffers as $offer) {
            $before = $offer->only(['status', 'version']);
            $offer->reject(Str::limit($reason, 255, ''));
            $offer->save();
            Audit::record($actor, 'offer.rejected', $offer, $before, $offer->only(['status', 'version']), $reason, $report);
            Inbox::offer($offer->seller_id, 'offer.rejected', 'Penawaran ditolak', $reason, $offer);
        }
    }
}
