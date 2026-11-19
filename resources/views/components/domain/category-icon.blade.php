@props(['category'])

@php
    $icon = match (true) {
        str($category->name)->lower()->contains(['desain', 'grafis']) => 'design',
        str($category->name)->lower()->contains(['pemrograman', 'web']) => 'web',
        str($category->name)->lower()->contains(['data', 'statistik']) => 'data',
        str($category->name)->lower()->contains(['video', 'foto']) => 'photo',
        str($category->name)->lower()->contains(['penulisan', 'terjemahan']) => 'writing',
        str($category->name)->lower()->contains(['akademik', 'les']) => 'academic',
        default => null,
    };
@endphp

<img src="{{ asset($icon ? 'design/category-'.$icon.'.svg' : 'design/nav-explore.svg') }}" alt="" {{ $attributes }}>
