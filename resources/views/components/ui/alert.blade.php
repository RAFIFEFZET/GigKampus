@props(['tone' => 'info', 'title' => null])

<div role="{{ in_array($tone, ['danger', 'error']) ? 'alert' : 'status' }}" {{ $attributes->class(['alert', 'alert-'.$tone]) }}>
    @if ($title)<p class="font-semibold">{{ $title }}</p>@endif
    {{ $slot }}
</div>
