@props(['verification' => null])

<div class="flex flex-wrap items-center gap-2">
    <x-ui.badge tone="success">✓ Akun terdaftar</x-ui.badge>
    @if ($verification)
        <x-domain.status-badge :status="$verification->status"/>
    @else
        <x-ui.badge tone="warning">Verifikasi KTM</x-ui.badge>
    @endif
</div>
