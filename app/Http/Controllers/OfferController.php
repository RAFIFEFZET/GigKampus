<?php

namespace App\Http\Controllers;

use App\Exceptions\StaleOffer;
use App\Models\PriceOffer;
use App\Models\ServiceRequest;
use App\Services\OfferWorkflow;
use App\Services\RequestDetail;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class OfferController extends Controller
{
    public function __construct(private readonly OfferWorkflow $workflow, private readonly RequestDetail $detail) {}

    public function index(Request $request): View
    {
        return view('pages.studio.offers.index', [
            'offers' => $request->user()->priceOffers()
                ->with(['serviceRequest.buyer', 'serviceRequest.category', 'seller'])
                ->latest('offered_at')->orderByDesc('id')->paginate(12),
        ]);
    }

    public function store(Request $request, ServiceRequest $serviceRequest): Response|RedirectResponse
    {
        Gate::authorize('create', [PriceOffer::class, $serviceRequest]);
        $terms = $this->validateTerms($request);

        try {
            $this->workflow->submit($request->user(), $serviceRequest, $terms);
        } catch (StaleOffer $conflict) {
            return $this->conflict($request, $serviceRequest, $conflict, $terms);
        }

        return to_route('requests.show', $serviceRequest)->with('status', 'Penawaran berhasil dikirim.');
    }

    public function update(Request $request, PriceOffer $offer): Response|RedirectResponse
    {
        Gate::authorize('manage', $offer);
        $terms = $this->validateTerms($request, true);

        try {
            $this->workflow->update($request->user(), $offer, $terms);
        } catch (StaleOffer $conflict) {
            return $this->conflict($request, $offer->serviceRequest, $conflict, $terms);
        }

        return to_route('requests.show', $offer->serviceRequest)->with('status', 'Perubahan penawaran disimpan.');
    }

    public function withdraw(Request $request, PriceOffer $offer): Response|RedirectResponse
    {
        Gate::authorize('manage', $offer);
        $versions = $request->validate($this->versionRules(true));

        try {
            $this->workflow->withdraw($request->user(), $offer, $versions);
        } catch (StaleOffer $conflict) {
            return $this->conflict($request, $offer->serviceRequest, $conflict, []);
        }

        return to_route('requests.show', $offer->serviceRequest)->with('status', 'Penawaran ditarik.');
    }

    private function validateTerms(Request $request, bool $updating = false): array
    {
        $terms = $request->validate([
            'offered_price' => ['required', 'integer', 'between:1,100000000'],
            'max_revisions' => ['required', 'integer', 'between:0,20'],
            'proposal_note' => ['required', 'string', 'min:20', 'max:3000'],
            ...$this->versionRules($updating),
        ]);
        $terms['offered_price'] = (int) $terms['offered_price'];

        return $terms;
    }

    private function versionRules(bool $withOfferVersion): array
    {
        $rules = [
            'request_version' => ['required', 'integer', 'between:1,2147483647'],
        ];
        if ($withOfferVersion) {
            $rules['offer_version'] = ['required', 'integer', 'between:1,2147483647'];
        }

        return $rules;
    }

    private function conflict(Request $request, ServiceRequest $serviceRequest, StaleOffer $conflict, array $terms): Response
    {
        if ($request->expectsJson()) {
            return response(['message' => $conflict->getMessage()], 409);
        }

        return $this->detail->render(
            $serviceRequest->refresh(),
            $request->user(),
            $conflict->getMessage(),
            Arr::only($terms, ['offered_price', 'max_revisions', 'proposal_note']),
        );
    }
}
