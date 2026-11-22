@props(['name', 'label', 'value' => null, 'id' => null, 'help' => null, 'min' => null, 'max' => null])

@php
    $id ??= $name;
    $oldValue = old($name, $value);
    $display = is_scalar($oldValue) ? (string) $oldValue : '';
    $binding = $attributes->whereStartsWith('wire:model');
@endphp

<x-ui.field :$name :$id :$label :$help>
    <div class="currency-field" x-data="gigkampusCurrency(@js($min === null ? null : (int) $min), @js($max === null ? null : (int) $max))"
        x-init="$nextTick(() => sync($refs.display))">
        <span class="currency-prefix" aria-hidden="true">Rp</span>
        <input id="{{ $id }}" name="{{ $name }}" type="text" inputmode="numeric" maxlength="20" value="{{ $display }}"
            data-currency-display x-ref="display" x-bind:name="null" x-on:input="sync($event.target, $event)"
            @if ($help || $errors->has($name)) aria-describedby="{{ trim(($help ? $id.'-help ' : '').($errors->has($name) ? $id.'-error' : '')) }}" @endif
            @if ($errors->has($name)) aria-invalid="true" @endif
            {{ $attributes->whereDoesntStartWith('wire:model')->class('field-control') }}>
        <input type="hidden" name="{{ $name }}" disabled x-ref="raw" x-bind:disabled="$refs.display.disabled" {{ $binding }}>
    </div>
</x-ui.field>
