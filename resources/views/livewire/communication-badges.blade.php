<div class="header-actions" x-data x-on:gigkampus:counts.window="$wire.$refresh()">
    @unless ($isAdmin)
        <a class="chat-pill" href="{{ route('chat.index') }}">
            <img src="{{ asset('design/chat.svg') }}" alt="" width="18" height="18">
            Chat
            <x-domain.unread-badge :count="$chat" label="pesan" data-chat-unread/>
        </a>
    @endunless
    <a class="relative grid size-9 shrink-0 place-items-center rounded-full hover:bg-campus-background"
        href="{{ route($isAdmin ? 'admin.inbox.index' : 'inbox.index') }}" aria-label="Notifikasi">
        <img src="{{ asset('design/bell.svg') }}" alt="" width="18" height="18">
        <x-domain.unread-badge class="absolute -top-1 -right-1" :count="$inbox" label="notifikasi" data-inbox-unread/>
    </a>
</div>
