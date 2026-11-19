@props(['gig'])

<a class="gig-card" href="{{ route('catalog.show', $gig) }}">
    <div class="gig-cover">
        @if (in_array(strtolower(pathinfo($gig->portfolio_path ?? '', PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true))
            <img src="{{ Storage::disk('public')->url($gig->portfolio_path) }}" alt="" loading="lazy">
        @else
            <x-domain.category-icon :category="$gig->category" class="gig-fallback"/>
        @endif
        @if ($gig->seller_rating !== null)
            <x-ui.badge>★ {{ number_format($gig->seller_rating, 1, ',', '.') }}</x-ui.badge>
        @endif
    </div>
    <div class="gig-body">
        <span class="seller-line">
            <x-ui.avatar :user="$gig->seller" class="size-[22px]"/>
            <span>{{ $gig->seller->name }}</span>
            <x-domain.seller-verified-badge :verified="$gig->seller->is_verified"/>
        </span>
        <h3>{{ $gig->title }}</h3>
        <span class="price">Harga tetap <strong>Rp{{ number_format($gig->base_price, 0, ',', '.') }}</strong></span>
    </div>
</a>
