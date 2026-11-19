@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'id' => null, 'help' => null])

@php($oldValue = old($name, $value))

<x-ui.field :$name :$id :$label :$help>
    @if ($type === 'password')
        <div class="relative" x-data="{ visible: false }">
    @endif
    <input
        id="{{ $id ?? $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type === 'password') x-bind:type="visible ? 'text' : 'password'" @endif
        @unless(in_array($type, ['password', 'file'], true)) value="{{ is_scalar($oldValue) ? $oldValue : '' }}" @endunless
        @if ($help || $errors->has($name)) aria-describedby="{{ trim(($help ? ($id ?? $name).'-help ' : '').($errors->has($name) ? ($id ?? $name).'-error' : '')) }}" @endif
        @if ($errors->has($name)) aria-invalid="true" @endif
        {{ $attributes->class(['field-control', 'pr-12' => $type === 'password']) }}
    >
    @if ($type === 'password')
            <button type="button" class="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-campus-field"
                x-on:click="visible = !visible" x-bind:aria-pressed="visible" aria-label="Tampilkan kata sandi"
                aria-controls="{{ $id ?? $name }}">
                <img src="{{ asset('design/password-visibility.svg') }}" alt="" width="18" height="18">
            </button>
        </div>
    @endif
</x-ui.field>
