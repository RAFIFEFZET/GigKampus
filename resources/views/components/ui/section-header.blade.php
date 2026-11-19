@props(['title', 'subtitle' => null, 'level' => 1])

<header {{ $attributes->class('page-title') }}>
    @if ((int) $level === 2)
        <h2>{{ $title }}</h2>
    @else
        <h1>{{ $title }}</h1>
    @endif
    @if ($subtitle)<p>{{ $subtitle }}</p>@endif
    {{ $slot }}
</header>
