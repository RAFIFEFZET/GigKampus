<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class RequestDetail
{
    public function render(ServiceRequest $serviceRequest, User|Admin|null $viewer, ?string $conflict = null, array $retained = []): Response
    {
        $serviceRequest->loadMissing(['category', 'buyer']);
        $gate = Gate::forUser($viewer);
        abort_unless($gate->allows('view', $serviceRequest), 404);

        $offers = $serviceRequest->priceOffers()->with('seller:id,name,avatar_path,is_verified')
            ->latest('offered_at')->orderByDesc('id');
        $canViewAllOffers = $gate->allows('viewOffers', $serviceRequest);
        if (! $canViewAllOffers) {
            $offers->where('seller_id', $viewer?->id);
        }
        $offers = $offers->get();
        $ownOffer = $canViewAllOffers ? null : $offers->first();

        return response()->view('pages.requests.show', [
            'serviceRequest' => $serviceRequest,
            'offers' => $offers,
            'ownOffer' => $ownOffer,
            'canOffer' => $viewer instanceof User && $ownOffer === null && $gate->allows('create', [PriceOffer::class, $serviceRequest]),
            'canEdit' => $viewer instanceof User && $gate->allows('update', $serviceRequest),
            'canClose' => $viewer instanceof User && $gate->allows('close', $serviceRequest),
            'conflict' => $conflict,
            'retained' => $retained,
        ], $conflict === null ? 200 : 409);
    }
}
