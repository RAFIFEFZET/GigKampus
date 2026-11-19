@props(['title'])

<div x-data>
    <span x-on:click="$refs.dialog.showModal()">{{ $trigger }}</span>
    <dialog x-ref="dialog" aria-label="{{ $title }}" {{ $attributes->class('dialog') }}>
        <div class="stack">
            <h2>{{ $title }}</h2>
            {{ $slot }}
            <form method="dialog">
                <x-ui.button variant="outline" type="submit">Batal</x-ui.button>
            </form>
        </div>
    </dialog>
</div>
