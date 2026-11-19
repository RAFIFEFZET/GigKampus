@props(['request'])

<article class="request-card">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-ui.badge tone="info">{{ $request->category->name }}</x-ui.badge>
        <x-domain.status-badge :status="$request->status"/>
    </div>
    <h3><a class="hover:text-campus-link" href="{{ route('requests.show', $request) }}">{{ $request->title }}</a></h3>
    <p class="line-clamp-2 whitespace-pre-wrap text-sm text-campus-muted">{{ $request->description }}</p>
    <div class="flex flex-wrap items-center gap-2 text-xs text-campus-muted">
        <x-ui.avatar :user="$request->buyer" class="size-[22px]"/>
        <span>{{ $request->buyer->name }}</span>
        <x-domain.seller-verified-badge :verified="$request->buyer->is_verified"/>
        <span>Tenggat {{ $request->deadline_date->locale('id')->translatedFormat('j M Y') }}</span>
    </div>
    <div class="flex flex-wrap items-end justify-between gap-3 pt-1">
        <div>
            <p class="text-xs text-campus-muted">Anggaran</p>
            <p class="font-semibold">Rp{{ number_format($request->budget_min, 0, ',', '.') }} – Rp{{ number_format($request->budget_max, 0, ',', '.') }}</p>
        </div>
        <x-ui.button :href="route('requests.show', $request)">Lihat Permintaan</x-ui.button>
    </div>
</article>
