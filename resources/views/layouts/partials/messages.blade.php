@if (session('success') || session('status'))
    <x-ui.alert tone="success">{{ session('success') ?? session('status') }}</x-ui.alert>
@endif
@if (session('error'))
    <x-ui.alert tone="danger">{{ session('error') }}</x-ui.alert>
@endif
