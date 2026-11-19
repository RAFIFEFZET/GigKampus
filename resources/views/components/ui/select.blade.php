@props(['name', 'label' => null, 'value' => null, 'id' => null, 'help' => null, 'options' => null])

@php($oldValue = old($name, $value))

<x-ui.field :$name :$id :$label :$help>
    <select id="{{ $id ?? $name }}" name="{{ $name }}" {{ $attributes->class('field-control') }} @if ($errors->has($name)) aria-invalid="true" @endif>
        @if ($options !== null)
            @foreach ($options as $key => $text)
                <option value="{{ $key }}" @selected(is_scalar($oldValue) && (string) $oldValue === (string) $key)>{{ $text }}</option>
            @endforeach
        @endif
        {{ $slot }}
    </select>
</x-ui.field>
