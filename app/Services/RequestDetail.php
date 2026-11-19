<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Orders\OfferAcceptanceService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class RequestDetail
{
    public function __construct(private readonly OfferAcceptanceService $acceptance) {}

    public function render(ServiceRequest $serviceRequest, User|Admin|null $viewer, ?string $conflict = null, array $retained = []): Response
    {
        $serviceRequest->loadMissing(['category', 'buyer']);
        $gate = Gate::forUser($viewer);
        abort_unless($gate->allows('view', $serviceRequest), 404);

        $offers = $serviceRequest->priceOffers()->with('seller:id,name,avatar_path,is_verified,is_suspended,bank_id,bank_account_number,bank_account_holder_name,qris_image_path,qris_holder_name')
            ->latest('offered_at')->orderByDesc('id');
        $canViewAllOffers = $gate->allows('viewOffers', $serviceRequest);
        if (! $canViewAllOffers) {
            $offers->where('seller_id', $viewer?->id);
        }
        $offers = $offers->get();
        $ownOffer = $canViewAllOffers ? null : $offers->first();
        $acceptableOfferIds = [];

        if ($viewer instanceof User && $viewer->hasId($serviceRequest->buyer_id)) {
            foreach ($offers as $offer) {
                $offer->setRelation('serviceRequest', $serviceRequest);
                if ($this->acceptance->canAccept($viewer, $offer)) {
                    $acceptableOfferIds[] = $offer->id;
                }
            }
        }

        return response()->view('pages.requests.show', [
            'serviceRequest' => $serviceRequest,
            'offers' => $offers,
            'ownOffer' => $ownOffer,
            'canOffer' => $viewer instanceof User && $ownOffer === null && $gate->allows('create', [PriceOffer::class, $serviceRequest]),
            'canEdit' => $viewer instanceof User && $gate->allows('update', $serviceRequest),
            'canClose' => $viewer instanceof User && $gate->allows('close', $serviceRequest),
            'acceptableOfferIds' => $acceptableOfferIds,
            'conflict' => $conflict,
            'retained' => $retained,
        ], $conflict === null ? 200 : 409);
    }
}
