<?php

namespace App\Livewire\Chat;

use App\Models\ChatRoom;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Conversation extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $roomId = null;

    public string $search = '';

    public bool $unreadOnly = false;

    public function mount(?ChatRoom $room = null): void
    {
        $this->roomId = $room?->id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage('percakapan');
    }

    public function updatedUnreadOnly(): void
    {
        $this->resetPage('percakapan');
    }

    public function readVisible(ChatService $chat, int $throughId = 0): int
    {
        $student = $this->student();
        if ($this->roomId === null || $throughId < 1) {
            return 0;
        }

        $room = ChatRoom::findOrFail($this->roomId);
        Gate::forUser($student)->authorize('view', $room);

        return Gate::forUser($student)->allows('markRead', $room)
            ? $chat->markRead($student, $room, $throughId)
            : 0;
    }

    public function render(): View
    {
        $student = $this->student();
        $room = $this->roomId === null ? null : ChatRoom::with(['buyer', 'seller', 'order.payment', 'gig', 'priceOffer.serviceRequest'])->findOrFail($this->roomId);
        $gate = Gate::forUser($student);
        if ($room) {
            $gate->authorize('view', $room);
        }
        $search = mb_substr(trim($this->search), 0, 100);
        $pattern = '%'.addcslashes($search, '\\%_').'%';
        $incoming = fn (Builder $query) => $query->where('sender_id', '!=', $student->id)->whereNull('read_at');
        $rooms = ChatRoom::query()
            ->where(fn (Builder $query) => $query->where('buyer_id', $student->id)->orWhere('seller_id', $student->id))
            ->when(! $student->canCreateNewTransaction(), fn ($query) => $query->whereNotNull('order_id'))
            ->when($search !== '', fn ($query) => $query->where(fn (Builder $text) => $text
                ->whereHas('buyer', fn (Builder $buyer) => $buyer->where('id', '!=', $student->id)->where('name', 'ilike', $pattern))
                ->orWhereHas('seller', fn (Builder $seller) => $seller->where('id', '!=', $student->id)->where('name', 'ilike', $pattern))
                ->orWhereHas('order', fn (Builder $order) => $order->where('title_snapshot', 'ilike', $pattern))
                ->orWhereHas('gig', fn (Builder $gig) => $gig->where('title', 'ilike', $pattern))))
            ->when($this->unreadOnly, fn ($query) => $query->whereHas('messages', $incoming))
            ->with(['buyer', 'seller', 'order', 'gig', 'priceOffer.serviceRequest', 'latestMessage'])
            ->withCount(['messages as unread_count' => $incoming])->withMax('messages', 'sent_at')
            ->orderByRaw('messages_max_sent_at DESC NULLS LAST')->orderByDesc('id')
            ->paginate(20, pageName: 'percakapan');

        return view('livewire.chat.conversation', [
            'student' => $student,
            'rooms' => $rooms,
            'room' => $room,
            'messages' => $room?->messages()->latest('id')->paginate(35, pageName: 'riwayat'),
            'files' => $room?->messages()->whereNotNull('file_path')->latest('id')->limit(5)->get(),
            'canSend' => $room && $gate->allows('send', $room),
            'canRefund' => $room && $gate->allows('sendRefundProof', $room),
        ]);
    }

    private function student(): User
    {
        $student = Auth::guard('web')->user();
        abort_unless($student instanceof User && ($this->roomId !== null || $student->is_verified), 403);

        return $student;
    }
}
