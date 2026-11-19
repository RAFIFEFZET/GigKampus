<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@hasSection('title')@yield('title') · @endif GigKampus</title>
<link rel="icon" href="{{ asset('design/logo.svg') }}" type="image/svg+xml">
@vite('resources/css/app.css')
@livewireStyles
