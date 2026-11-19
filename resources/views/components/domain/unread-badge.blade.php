@props(['count', 'label'])

@if ($count > 0)
    <span {{ $attributes->class('counter') }} aria-label="{{ $count }} {{ $label }} belum dibaca">{{ min($count, 99) }}</span>
@endif
