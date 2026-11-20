<!doctype html>
<html lang="id">
<head>
    @include('layouts.partials.head')
</head>
<body class="min-h-screen bg-campus-background">
    @include('layouts.partials.header', ['mobileNav' => false])
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <p class="w-full pb-2 text-lg font-bold">GigKampus Admin</p>
            <nav aria-label="Navigasi admin" class="flex flex-wrap gap-1 lg:flex-col">
                @foreach ([
                    'Ringkasan' => ['admin.index', 'admin.index'],
                    'Review KTM' => ['admin.ktm.index', 'admin.ktm.*'],
                    'Kategori' => ['admin.categories.index', 'admin.categories.*'],
                    'Antrean Laporan' => ['admin.reports.index', 'admin.reports.*'],
                    'Moderasi' => ['admin.moderation.index', 'admin.moderation.*'],
                ] as $label => [$route, $pattern])
                    <a href="{{ route($route) }}" @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </aside>
        <main class="admin-main stack">
            @include('layouts.partials.messages')
            @yield('content')
        </main>
    </div>
    @livewireScripts
</body>
</html>
