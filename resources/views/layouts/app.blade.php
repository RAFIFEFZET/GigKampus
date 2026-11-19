<!doctype html>
<html lang="id">
<head>
    @include('layouts.partials.head')
</head>
<body class="site-body @yield('body-class')">
    @include('layouts.partials.header')
    <main id="konten">
        @if (session()->hasAny(['success', 'status', 'error']))
            <div class="shell pt-4">@include('layouts.partials.messages')</div>
        @endif
        @yield('content')
    </main>
    <footer class="site-footer">
        <div class="shell flex flex-wrap items-center justify-between gap-2">
            <a href="{{ route('home') }}" class="font-bold">GigKampus</a>
            <p class="text-xs text-campus-muted">© {{ now()->year }} GigKampus · IPB University</p>
        </div>
    </footer>
    @livewireScripts
</body>
</html>
