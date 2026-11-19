<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Gig;
use App\Models\Review;
use App\Services\MarketplaceBrowse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CatalogController extends Controller
{
    public function __construct(private readonly MarketplaceBrowse $browse) {}

    public function home(): View
    {
        return view('pages.catalog.home', [
            'gigs' => $this->browse->gigs()->limit(6)->get(),
            'requests' => $this->browse->requests()->limit(3)->get(),
            'categories' => Category::active()->orderBy('id')->limit(6)->get(),
            'campusRating' => Review::visible()->avg('rating'),
        ]);
    }

    public function index(Request $request): View
    {
        $filters = $this->browse->catalogFilters($request);

        return view('pages.catalog.index', [
            'gigs' => $this->browse->gigs($filters)->paginate(12)->withQueryString(),
            'categories' => Category::active()->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $gig): View
    {
        $gig = $this->browse->gigs()->findOrFail($gig);
        $reviews = $gig->seller->receivedReviews()->visible()->with('reviewer:id,name,avatar_path')
            ->latest('review_date')->orderByDesc('id')->limit(6)->get();

        return view('pages.catalog.show', [
            'gig' => $gig,
            'reviews' => $reviews,
            'canOrder' => Gate::forUser($request->user('web'))->allows('order', $gig),
        ]);
    }

    public function portfolio(string $gig): StreamedResponse
    {
        $gig = Gig::publiclyVisible()->findOrFail($gig);
        $disk = Storage::disk('public');
        abort_unless($gig->portfolio_path && $disk->exists($gig->portfolio_path), 404);

        return $disk->download($gig->portfolio_path);
    }
}
