@props(['message', 'own' => false])

<li {{ $attributes->class(['chat-message', 'chat-message-own' => $own]) }} data-message-id="{{ $message->id }}">
    <div class="chat-bubble">
        @if ($message->message_type === \App\Enums\ChatMessageType::RefundProof)
            <x-ui.badge tone="warning" class="mb-1">Bukti Refund</x-ui.badge>
        @endif
        @if ($message->message_body)<p class="whitespace-pre-wrap">{{ $message->message_body }}</p>@endif
        @if ($message->file_path)
            <a class="chat-attachment" href="{{ route('files.show', ['jenis' => 'chat_attachment', 'id' => $message->id]) }}">
                <img src="{{ asset('design/chat-document.svg') }}" alt="">
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold">{{ $message->original_filename }}</span>
                    <span class="text-xs text-campus-muted">{{ number_format($message->file_size / 1024, 1, ',', '.') }} KB</span>
                </span>
                <img src="{{ asset('design/chat-download.svg') }}" alt="Unduh">
            </a>
        @endif
        @if ($message->link_url)
            <a class="underline" href="{{ $message->link_url }}" target="_blank" rel="noopener noreferrer">{{ $message->link_url }} ↗</a>
        @endif
    </div>
    <p class="text-xs text-campus-muted">
        <x-ui.wib-time :date="$message->sent_at"/>
        @if ($own && $message->read_at)<span data-message-read> · Dibaca</span>@endif
    </p>
</li>
