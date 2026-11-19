@props(['user' => null, 'name' => null, 'src' => null])

@php
    $name ??= $user?->name ?? 'Akun';
    $src ??= $user?->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null;
@endphp

<span {{ $attributes->class('avatar') }} aria-hidden="true">
    @if ($src)
        <img src="{{ $src }}" alt="" loading="lazy">
    @else
        {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
    @endif
</span>
