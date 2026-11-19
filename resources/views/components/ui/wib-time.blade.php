@props(['date', 'withTime' => true])

@if ($date)
    <time datetime="{{ $date->toIso8601String() }}" {{ $attributes }}>
        {{ $date->timezone('Asia/Jakarta')->locale('id')->translatedFormat($withTime ? 'j M Y, H:i' : 'j M Y') }}{{ $withTime ? ' WIB' : '' }}
    </time>
@endif
