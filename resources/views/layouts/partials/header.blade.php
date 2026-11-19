@php
    $navigation = [
        ['Beranda', 'Beranda', 'home', 'home', 'home'],
        ['Eksplorasi Jasa', 'Eksplor', 'catalog.index', 'catalog.*', 'explore'],
        ['Papan Permintaan', 'Permintaan', 'requests.index', 'requests.*', 'request'],
        ['Pesanan Saya', 'Pesanan', 'orders.index', 'orders.*', 'orders'],
        ['Studio Seller', 'Studio Seller', 'studio.index', 'studio.*', 'seller'],
    ];
@endphp
<header class="site-header">
    <div class="header-inner">
        <a href="{{ route('home') }}" class="brand">
            <img src="{{ asset('design/logo.svg') }}" alt=""> GigKampus
        </a>
        <form action="{{ route('catalog.index') }}" role="search" class="header-search">
            <label class="search-pill">
                <img src="{{ asset('design/search.svg') }}" alt="" width="18" height="18">
                <span class="sr-only">Cari jasa</span>
                <input name="q" type="search" placeholder="Cari jasa desain, web, olah data…" maxlength="120">
            </label>
        </form>
        <nav class="desktop-nav" aria-label="Navigasi utama">
            @foreach ($navigation as [$label, $short, $route, $pattern, $icon])
                <a href="{{ route($route) }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="header-actions">
            @unless ($admin)
                <a class="chat-pill" href="{{ route('chat.index') }}">
                    <img src="{{ asset('design/chat.svg') }}" alt="" width="18" height="18">
                    Chat
                    @if ($unreadChat)
                        <span class="counter" aria-label="{{ $unreadChat }} pesan belum dibaca">{{ min($unreadChat, 99) }}</span>
                    @endif
                </a>
            @endunless
            @if ($student)
                <a class="grid size-9 shrink-0 place-items-center rounded-full hover:bg-campus-background" href="{{ route('inbox.index') }}" aria-label="Notifikasi">
                    <img src="{{ asset('design/bell.svg') }}" alt="" width="18" height="18">
                </a>
            @endif
            @if ($student || $admin)
                <details class="account-menu">
                    <summary aria-label="Menu akun">
                        <x-ui.avatar :user="$student ?? $admin"/>
                        <img src="{{ asset('design/chevron.svg') }}" alt="" width="12" height="12" class="hidden sm:block">
                    </summary>
                    <div class="account-menu-panel">
                        <p class="px-2 py-1 text-xs font-semibold text-campus-muted">{{ ($student ?? $admin)->name }}</p>
                        @if ($student)
                            <a href="{{ route('profile') }}">Profil</a>
                            <a href="{{ route('ktm.index') }}">Verifikasi KTM</a>
                        @else
                            <a href="{{ route('admin.index') }}">Dashboard Admin</a>
                        @endif
                        <form action="{{ route('logout') }}" method="post">
                            @csrf
                            <button type="submit">Keluar</button>
                        </form>
                    </div>
                </details>
            @else
                <a class="btn btn-outline px-3 py-1.5" href="{{ route('login') }}">Masuk</a>
            @endif
        </div>
    </div>
</header>
@if ($mobileNav ?? true)
    <nav class="mobile-nav" aria-label="Navigasi utama seluler">
        @foreach ($navigation as [$label, $short, $route, $pattern, $icon])
            <a href="{{ route($route) }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>
                <img src="{{ asset('design/nav-'.$icon.'.svg') }}" alt="">
                <span>{{ $short }}</span>
            </a>
        @endforeach
    </nav>
@endif
