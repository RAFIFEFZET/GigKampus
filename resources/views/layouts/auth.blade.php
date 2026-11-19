<!doctype html>
<html lang="id">
<head>
    @include('layouts.partials.head')
</head>
<body class="site-body bg-campus-background">
    @include('layouts.partials.header', ['mobileNav' => false])
    @php($signup = request()->routeIs('register'))
    <main class="shell grid flex-1 items-center py-8 sm:py-16">
        <div class="auth-wrap">
            <aside class="auth-illustration" aria-hidden="true">
                <span class="brand"><img src="{{ asset('design/logo.svg') }}" alt=""> GigKampus</span>
                <p class="mt-8 text-3xl font-bold leading-tight tracking-tight">
                    {{ $signup ? 'Mulai berkarya bersama mahasiswa IPB.' : 'Satu akun untuk memesan & menawarkan keahlian.' }}
                </p>
                <img class="auth-contour" src="{{ asset('design/'.($signup ? 'register' : 'login').'-contour.png') }}" alt="">
                <img class="auth-student" src="{{ asset('design/'.($signup ? 'register' : 'login').'-student.png') }}" alt="">
                <x-ui.badge tone="warning">Marketplace Mahasiswa IPB</x-ui.badge>
                <x-ui.badge tone="info">✓ Mahasiswa Terverifikasi</x-ui.badge>
            </aside>
            <section class="auth-panel">
                <nav class="auth-tabs" aria-label="Akses akun">
                    <a href="{{ route('login') }}" @unless ($signup) aria-current="page" @endunless>Masuk</a>
                    <a href="{{ route('register') }}" @if ($signup) aria-current="page" @endif>Daftar Akun Baru</a>
                </nav>
                @include('layouts.partials.messages')
                @yield('content')
            </section>
        </div>
    </main>
    @livewireScripts
</body>
</html>
