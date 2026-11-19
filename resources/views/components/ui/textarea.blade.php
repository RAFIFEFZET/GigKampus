@props(['name', 'label' => null, 'value' => null, 'id' => null, 'help' => null, 'rows' => 4])

@php($oldValue = old($name, $value))

<x-ui.field :$name :$id :$label :$help>
    <textarea
        id="{{ $id ?? $name }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @if ($errors->has($name)) aria-invalid="true" @endif
        {{ $attributes->class('field-control') }}
    >{{ is_scalar($oldValue) ? $oldValue : '' }}</textarea>
</x-ui.field>
