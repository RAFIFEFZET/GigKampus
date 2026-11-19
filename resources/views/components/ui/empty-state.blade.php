@props(['title', 'description' => null])

<div {{ $attributes->class('empty-state') }}>
    <h3 class="text-lg">{{ $title }}</h3>
    @if ($description)<p class="mt-1 text-sm text-campus-muted">{{ $description }}</p>@endif
    @if ($slot->isNotEmpty())<div class="mt-4">{{ $slot }}</div>@endif
</div>
