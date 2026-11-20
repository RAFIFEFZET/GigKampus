@extends('layouts.admin')
@section('title', 'Detail Mediasi')

@section('content')
    <a class="text-campus-link" href="{{ route('admin.reports.index') }}">← Antrean Laporan</a>
    <x-ui.section-header :title="$report->report_type->label().' #'.$report->id" subtitle="Detail Mediasi"/>
    <x-ui.card class="moderation-panel moderation-stack">
        <div class="moderation-actions">
            <x-domain.moderation-badge :status="$report->status"/>
            <x-ui.wib-time :date="$report->created_at" class="text-sm text-campus-muted"/>
        </div>
        <h2>{{ $report->category->label() }}</h2>
        @if ($report->order)
            <p>{{ $report->order->reference() }} · {{ $report->order->title_snapshot }}</p>
            <div class="moderation-actions">
                <span>Tahap sebelum sengketa</span>
                <x-domain.status-badge :status="$report->order_status_before_dispute"/>
            </div>
        @endif
        @if ($canHandle)
            <p class="text-sm">Pelapor {{ $report->reporter->name }}</p>
            @if ($report->gig)<p>Gig · {{ $report->gig->title }}</p>@endif
            @if ($report->serviceRequest)<p>Permintaan · {{ $report->serviceRequest->title }}</p>@endif
            <p class="moderation-copy">{{ $report->description }}</p>
            @if ($report->review)
                <article class="space-y-2 border-t border-campus-border pt-3">
                    <h3>Ulasan asli · {{ $report->review->rating }}/5</h3>
                    <p class="moderation-copy">{{ $report->review->comment }}</p>
                </article>
            @endif
            <x-domain.report-evidence-link :entry="$report" kind="report_evidence"/>
        @elseif ($report->handled_by_admin_id === null)
            <form method="post" action="{{ route('admin.reports.claim', $report) }}">
                @csrf
                <x-ui.button type="submit">Ambil Kasus</x-ui.button>
            </form>
        @elseif ($report->decided_at)
            <h2>Putusan akhir</h2>
            <p class="moderation-copy">{{ $report->decision_note }}</p>
            <x-ui.wib-time :date="$report->decided_at"/>
        @endif
    </x-ui.card>
    @if ($canHandle)
        <div class="moderation-grid">
            <x-ui.card class="moderation-panel moderation-stack">
                <h2>Bukti dan Percakapan</h2>
                @if ($report->order)
                    <ul class="space-y-2 text-sm">
                        @if ($report->order->qris_image_path_snapshot)
                            <li><a class="text-campus-link underline" href="{{ route('files.show', ['jenis' => 'order_qris_snapshot', 'id' => $report->order_id]) }}">Buka QRIS pesanan</a></li>
                        @endif
                        @foreach ($proofs as $proof)
                            <li><a class="text-campus-link underline" href="{{ route('files.show', ['jenis' => 'payment_proof', 'id' => $proof->id]) }}">Bukti bayar percobaan {{ $proof->attempt_no }}</a>
                                <x-domain.status-badge :status="$proof->seller_review_status"/>
                                @if ($proof->rejection_reason)<p>{{ $proof->rejection_reason }}</p>@endif
                            </li>
                        @endforeach
                        @foreach ($deliveries as $delivery)
                            <li><x-domain.delivery-item :$delivery/></li>
                        @endforeach
                    </ul>
                @endif
                @if ($room)
                    <div>
                        <x-ui.button variant="secondary" :href="route('admin.reports.show', ['report' => $report, 'chat' => 1]).'#case-chat'">Baca Percakapan</x-ui.button>
                    </div>
                @endif
            </x-ui.card>
            <x-ui.card class="moderation-panel moderation-stack">
                <h2>Minta Klarifikasi</h2>
                <form method="post" class="moderation-stack" action="{{ route('admin.reports.clarify', $report) }}">
                    @csrf
                    <x-ui.select name="recipient_user_id" label="Penerima klarifikasi" required
                        :options="['' => 'Pilih pihak'] + $parties->pluck('name', 'id')->all()"/>
                    <x-ui.textarea name="question" label="Pertanyaan" minlength="5" maxlength="2000" required
                        help="Penerima memiliki waktu 24 jam. Admin tetap dapat meninjau jawaban susulan sebelum putusan."/>
                    <div><x-ui.button type="submit">Kirim Permintaan Klarifikasi</x-ui.button></div>
                </form>
            </x-ui.card>
        </div>
    @endif
    @if ($messages)
        <x-ui.card id="case-chat" class="moderation-panel moderation-stack">
            <x-ui.section-header level="2" title="Percakapan Terkait" subtitle="Akses peninjauan untuk kasus ini."/>
            <ul class="space-y-3">
                @forelse ($messages->getCollection()->reverse() as $message)
                    <li class="border-t border-campus-border pt-3">
                        <p class="mb-1 text-sm font-semibold">{{ $message->sender->name }}</p>
                        <ul><x-domain.chat-message-bubble :$message/></ul>
                    </li>
                @empty
                    <li>Belum ada pesan di ruang ini.</li>
                @endforelse
            </ul>
            <x-ui.pagination :items="$messages"/>
        </x-ui.card>
    @endif
    @foreach ($clarifications as $clarification)
        <x-domain.clarification-thread :$clarification show-recipient/>
    @endforeach
    @if ($awaitingClarification)
        <x-ui.alert tone="info">Putusan tersedia setelah jawaban klarifikasi diterima atau tenggatnya terlewat.</x-ui.alert>
    @elseif ($canHandle)
        <x-domain.admin-decision-panel :$report :$outcomes :$actions :$proofs :$payment/>
    @endif
@endsection
