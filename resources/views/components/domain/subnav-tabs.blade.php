@props(['links'])

<nav {{ $attributes->class('tabs') }} aria-label="Navigasi bagian">
    @foreach ($links as $label => $url)
        <a class="tab" href="{{ $url }}" @if (url()->current() === $url) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
