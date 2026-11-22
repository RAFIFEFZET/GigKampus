@props(['name'])

<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true" focusable="false" {{ $attributes->class('admin-action-icon') }}>
    @switch($name)
        @case('edit')
            <path d="m14 5 5 5M4 20l4.5-1 11-11a2.1 2.1 0 0 0-3-3l-11 11L4 20Z"/>
            @break
        @case('check')
            <path d="m5 12 4 4L19 6"/>
            @break
        @case('block')
            <circle cx="12" cy="12" r="8"/><path d="m6.4 6.4 11.2 11.2"/>
            @break
        @case('close')
            <rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 12h8"/>
            @break
        @case('hide')
            <path d="M4 4l16 16M10 6a11 11 0 0 1 2-.2c5 0 8.4 5 9 6.2a14 14 0 0 1-2.7 3.2M6.7 7.1A14 14 0 0 0 3 12c.6 1.2 4 6.2 9 6.2a9 9 0 0 0 3.2-.6"/>
            @break
        @case('view')
            <path d="M3 12c.6-1.2 4-6.2 9-6.2s8.4 5 9 6.2c-.6 1.2-4 6.2-9 6.2s-8.4-5-9-6.2Z"/><circle cx="12" cy="12" r="2.5"/>
            @break
        @case('info')
            <circle cx="12" cy="12" r="8"/><path d="M12 11v5m0-8h.01"/>
            @break
    @endswitch
</svg>
