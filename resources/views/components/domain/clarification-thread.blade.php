@props(['clarification', 'canRespond' => false, 'showRecipient' => false])

<x-ui.card class="moderation-panel moderation-stack">
    <div class="moderation-actions">
        <h2>Klarifikasi #{{ $clarification->id }}</h2>
        @if ($clarification->report->status === \App\Enums\ReportStatus::Pending && $clarification->isOverdue())
            <x-ui.badge tone="warning">Tenggat terlewat</x-ui.badge>
        @endif
    </div>
    @if ($showRecipient)<p class="text-sm">Penerima {{ $clarification->recipient->name }}</p>@endif
    <p class="text-sm text-campus-muted">Tenggat <x-ui.wib-time :date="$clarification->due_at"/></p>
    <p class="moderation-copy">{{ $clarification->question }}</p>
    @forelse ($clarification->responses as $response)
        <article class="space-y-2 border-t border-campus-border pt-3">
            <div class="moderation-actions text-sm text-campus-muted">
                <x-ui.wib-time :date="$response->submitted_at"/>
                @if ($response->isLate())<x-ui.badge tone="warning">Jawaban terlambat</x-ui.badge>@endif
            </div>
            @if ($response->response_text !== null)<p class="moderation-copy">{{ $response->response_text }}</p>@endif
            <x-domain.report-evidence-link :entry="$response" kind="clarification_evidence"/>
        </article>
    @empty
        <p class="text-sm text-campus-muted">Belum ada jawaban.</p>
    @endforelse
    @if ($canRespond)
        <form class="moderation-stack border-t border-campus-border pt-4" method="post" enctype="multipart/form-data"
            action="{{ route('reports.clarifications.respond', $clarification) }}">
            @csrf
            @if ($clarification->isOverdue())
                <x-ui.alert tone="warning">Tenggat sudah lewat. Jawaban masih dapat dikirim selama kasus menunggu putusan.</x-ui.alert>
            @endif
            <x-ui.textarea name="response_text" label="Jawaban susulan" maxlength="5000"
                help="Isi jawaban, unggah satu berkas, atau sertakan tautan bukti."/>
            <x-domain.report-evidence-fields/>
            <div><x-ui.button type="submit">Kirim Jawaban</x-ui.button></div>
        </form>
    @endif
</x-ui.card>
