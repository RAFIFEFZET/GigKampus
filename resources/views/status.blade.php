<!doctype html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        <title>Status GigKampus</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-campus-background text-slate-950">
        <main class="mx-auto max-w-xl px-5 py-16 sm:py-24">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="title">
                <header class="bg-campus-sky px-6 py-7 sm:px-8">
                    <h1 id="title" class="text-3xl font-semibold tracking-tight text-campus-blue">{{ config('app.name') }}</h1>
                    <p class="mt-1 text-sm text-slate-600">Status lingkungan lokal</p>
                </header>
                <dl class="divide-y divide-slate-200 px-6 sm:px-8">
                    <div class="flex flex-wrap items-center justify-between gap-2 py-5">
                        <dt class="text-sm font-medium">Laravel</dt>
                        <dd class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-800">Aplikasi berjalan</dd>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 py-5">
                        <dt class="text-sm font-medium">Database</dt>
                        <dd @class([
                            'rounded-full px-3 py-1 text-sm font-medium',
                            'bg-emerald-50 text-emerald-800' => $databaseConnected,
                            'bg-red-50 text-red-800' => ! $databaseConnected,
                        ])>
                            {{ $databaseConnected ? 'PostgreSQL terhubung' : 'PostgreSQL tidak tersedia' }}
                        </dd>
                    </div>
                </dl>
            </section>
        </main>
        @livewireScripts
    </body>
</html>
