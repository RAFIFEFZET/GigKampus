@props(['name', 'label' => null, 'help' => null, 'hint' => null, 'accept' => '.jpg,.jpeg,.png'])

<x-ui.input :$name :$label :help="$help ?? $hint" type="file" :$accept {{ $attributes->class('file-control') }}/>
