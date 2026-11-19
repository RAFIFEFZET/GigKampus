<?php

namespace App\Services;

use App\Models\Gig;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class MarketplaceBrowse
{
    public function filters(Request $request): array
    {
        $keyword = $request->query('q');

        return [
            'q' => is_string($keyword) ? mb_substr(trim($keyword), 0, 150) : '',
            'category' => filter_var($request->query('category'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
        ];
    }

    public function catalogFilters(Request $request): array
    {
        $price = $request->query('price');
        $duration = $request->query('duration');
        $sort = $request->query('sort');

        return [
            ...$this->filters($request),
            'price' => in_array($price, ['under_100k', '100k_250k', 'over_250k'], true) ? $price : '',
            'duration' => in_array($duration, ['3', '7'], true) ? $duration : '',
            'sort' => in_array($sort, ['rating', 'price_asc', 'price_desc'], true) ? $sort : 'newest',
        ];
    }

    public function gigs(array $filters = []): Builder
    {
        $query = Gig::publiclyVisible()->withSellerRating()->with(['category', 'seller']);

        if (filled($filters['category'] ?? null)) {
            $query->where('category_id', $filters['category']);
        }
        if (filled($filters['q'] ?? null)) {
            $pattern = $this->pattern($filters['q']);
            $query->where(fn (Builder $search) => $search
                ->where('title', 'ilike', $pattern)
                ->orWhere('description', 'ilike', $pattern)
                ->orWhereHas('seller', fn (Builder $seller) => $seller->where('name', 'ilike', $pattern)));
        }
        match ($filters['price'] ?? '') {
            'under_100k' => $query->where('base_price', '<', 100000),
            '100k_250k' => $query->whereBetween('base_price', [100000, 250000]),
            'over_250k' => $query->where('base_price', '>', 250000),
            default => null,
        };
        if (filled($filters['duration'] ?? null)) {
            $query->where('delivery_days', '<=', $filters['duration']);
        }
        match ($filters['sort'] ?? 'newest') {
            'rating' => $query->orderByRaw('seller_rating DESC NULLS LAST'),
            'price_asc' => $query->orderBy('base_price'),
            'price_desc' => $query->orderByDesc('base_price'),
            default => null,
        };

        return $query->latest()->orderByDesc('id');
    }

    public function requests(array $filters = []): Builder
    {
        $query = ServiceRequest::publiclyVisible()->with(['category', 'buyer']);

        if (filled($filters['category'] ?? null)) {
            $query->where('category_id', $filters['category']);
        }
        if (filled($filters['q'] ?? null)) {
            $pattern = $this->pattern($filters['q']);
            $query->where(fn (Builder $search) => $search
                ->where('title', 'ilike', $pattern)
                ->orWhere('description', 'ilike', $pattern));
        }

        return $query->latest()->orderByDesc('id');
    }

    private function pattern(string $keyword): string
    {
        return '%'.addcslashes($keyword, '\\%_').'%';
    }
}
