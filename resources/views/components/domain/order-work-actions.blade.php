@props(['order', 'isBuyer' => false, 'isSeller' => false])

@if ($isSeller && in_array($order->status, [\App\Enums\OrderStatus::InProgress, \App\Enums\OrderStatus::Revision], true))
    @php
        $activeRevision = $order->revisions->first(fn ($revision) => in_array($revision->status,
            [\App\Enums\RevisionStatus::Requested, \App\Enums\RevisionStatus::InProgress], true));
    @endphp
    <x-ui.card class="stack">
        <x-ui.section-header level="2" :title="$order->status === \App\Enums\OrderStatus::Revision ? 'Kirim Hasil Perbaikan' : 'Kirim Hasil Pekerjaan'"/>
        @if ($order->status === \App\Enums\OrderStatus::Revision && $activeRevision?->status === \App\Enums\RevisionStatus::Requested)
            <form method="post" action="{{ route('orders.revisions.start', [$order, $activeRevision]) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Tandai Revisi Sedang Dikerjakan</x-ui.button>
            </form>
        @endif
        <form class="stack" method="post" action="{{ route('orders.deliveries.store', $order) }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="stage_version" value="{{ $order->stage_version }}">
            @if ($activeRevision)<input type="hidden" name="revision_id" value="{{ $activeRevision->id }}">@endif
            <div class="order-upload">
                <img src="{{ asset('design/order-upload.svg') }}" alt="">
                <x-ui.file-upload name="delivery_file" label="Unggah Berkas Hasil" accept=".pdf,.docx,.xlsx,.pptx,.txt,.csv,.jpg,.jpeg,.png,.zip"
                    help="PDF, DOCX, XLSX, PPTX, TXT, CSV, JPG, PNG, atau ZIP. Maksimal 20 MB."/>
            </div>
            <x-ui.input name="result_url" label="Atau Tautan Hasil HTTPS" type="url" maxlength="2048" placeholder="https://"/>
            <x-ui.textarea name="delivery_note" label="Catatan Pengiriman (opsional)" maxlength="2000" rows="3"/>
            @error('stage_version')<p class="field-error">{{ $message }}</p>@enderror
            <x-ui.button type="submit">Kirim Hasil</x-ui.button>
        </form>
    </x-ui.card>
@endif

@if ($isBuyer && $order->status === \App\Enums\OrderStatus::Delivered)
    <x-ui.card class="stack">
        <x-ui.section-header level="2" title="Tinjau Hasil"/>
        @if ($order->cancellation_status === \App\Enums\CancellationStatus::Pending)
            <x-ui.modal title="Konfirmasi pesanan selesai?">
                <x-slot:trigger><x-ui.button>Konfirmasi Pesanan Selesai</x-ui.button></x-slot:trigger>
                <p>Pesanan menjadi final. Pengajuan pembatalan aktif ditutup otomatis.</p>
                <form method="post" action="{{ route('orders.complete', $order) }}">
                    @csrf
                    <input type="hidden" name="stage_version" value="{{ $order->stage_version }}">
                    <x-ui.button type="submit">Ya, Selesaikan Pesanan</x-ui.button>
                </form>
            </x-ui.modal>
        @else
            <form method="post" action="{{ route('orders.complete', $order) }}">
                @csrf
                <input type="hidden" name="stage_version" value="{{ $order->stage_version }}">
                <x-ui.button type="submit">Konfirmasi Pesanan Selesai</x-ui.button>
            </form>
        @endif
        @if ($order->remainingRevisions() > 0)
            <form class="stack border-t border-campus-border pt-4" method="post" action="{{ route('orders.revisions.store', $order) }}">
                @csrf
                <input type="hidden" name="stage_version" value="{{ $order->stage_version }}">
                <h3>Ajukan Revisi · sisa {{ $order->remainingRevisions() }}</h3>
                <x-ui.textarea name="revision_note" label="Catatan Revisi" minlength="20" maxlength="5000" required/>
                <x-ui.button class="self-start" type="submit" variant="secondary">Kirim Revisi</x-ui.button>
            </form>
        @else
            <p class="text-sm text-campus-muted">Kuota revisi sudah habis.</p>
        @endif
    </x-ui.card>
@endif
