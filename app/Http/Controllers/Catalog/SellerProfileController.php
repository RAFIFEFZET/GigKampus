<?php

namespace App\Http\Controllers\Catalog;

use App\Models\User;
use App\Services\MarketplaceBrowse;
use Illuminate\Contracts\View\View;

final class SellerProfileController
{
    public function __invoke(User $seller, MarketplaceBrowse $browse): View
    {
        abort_unless($seller->is_verified && ! $seller->is_suspended, 404);

        $gigs = $browse->gigs()->where('seller_id', $seller->id)->limit(4)->get();
        $rating = $seller->receivedReviews()->visible()->avg('rating');
        $reviews = $seller->receivedReviews()->visible()->with('reviewer')
            ->latest('review_date')->orderByDesc('id')->paginate(10);

        return view('pages.catalog.seller', compact('seller', 'gigs', 'rating', 'reviews'));
    }
}
