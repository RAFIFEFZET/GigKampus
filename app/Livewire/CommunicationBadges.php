<?php

namespace App\Livewire;

use App\Models\ChatMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class CommunicationBadges extends Component
{
    public function render(): View
    {
        $student = Auth::guard('web')->user();
        $admin = Auth::guard('admin')->user();
        $account = $admin ?? $student;
        abort_unless($account, 403);

        $chat = $student ? ChatMessage::query()
            ->whereNull('read_at')->where('sender_id', '!=', $student->id)
            ->whereHas('chatRoom', fn (Builder $room) => $room->where(
                fn (Builder $party) => $party->where('buyer_id', $student->id)->orWhere('seller_id', $student->id),
            )->when(! $student->canCreateNewTransaction(), fn (Builder $query) => $query->whereNotNull('order_id')))->count() : 0;

        return view('livewire.communication-badges', [
            'isAdmin' => $admin !== null,
            'chat' => $chat,
            'inbox' => $account->notifications()->whereNull('read_at')->count(),
        ]);
    }
}
