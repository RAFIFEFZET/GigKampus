@props(['room', 'student', 'selected' => false])

@php($party = $student->id === $room->buyer_id ? $room->seller : $room->buyer)
<a href="{{ route('chat.show', $room) }}" {{ $attributes->class(['chat-room-item', 'chat-room-selected' => $selected]) }}
    @if ($selected) aria-current="page" @endif>
    <x-ui.avatar :user="$party" class="size-10"/>
    <div class="min-w-0 flex-1">
        <p class="font-semibold">{{ $party->name }}</p>
        <p class="line-clamp-1 text-xs text-campus-muted">
            {{ $room->latestMessage?->message_body ?? ($room->latestMessage?->file_path ? 'Lampiran' : 'Percakapan') }}
        </p>
        <span class="text-xs text-campus-muted">{{ $room->order?->reference() ?? ($room->isArchived() ? 'Arsip' : 'Pra-pesan') }}</span>
    </div>
    <x-domain.unread-badge :count="$room->unread_count" label="pesan"/>
</a>
