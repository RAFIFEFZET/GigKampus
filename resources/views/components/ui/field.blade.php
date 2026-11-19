@props(['name', 'id' => null, 'label' => null, 'help' => null])

<div {{ $attributes->class('field') }}>
    @if ($label)
        <label for="{{ $id ?? $name }}">{{ $label }}</label>
    @endif
    {{ $slot }}
    @if ($help)
        <p id="{{ $id ?? $name }}-help" class="field-help">{{ $help }}</p>
    @endif
    @error($name)
        <p id="{{ $id ?? $name }}-error" class="field-error" role="alert">{{ $message }}</p>
    @enderror
</div>
