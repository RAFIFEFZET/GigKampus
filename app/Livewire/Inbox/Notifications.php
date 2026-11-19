<?php

namespace App\Livewire\Inbox;

use App\Events\NotificationChanged;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Notifications extends Component
{
    use WithPagination;

    public function markRead(int $id): void
    {
        $recipient = $this->recipient();
        $entry = $recipient->notifications()->findOrFail($id);

        $this->markUnreadAsRead($recipient, $recipient->notifications()->whereKey($entry->id), $entry->id);
    }

    public function markAllRead(): void
    {
        $recipient = $this->recipient();

        $this->markUnreadAsRead($recipient, $recipient->notifications());
    }

    public function render(): View
    {
        $recipient = $this->recipient();

        return view('livewire.notifications.index', [
            'entries' => $recipient->notifications()->latest()->orderByDesc('id')->paginate(12),
            'hasUnread' => $recipient->notifications()->whereNull('read_at')->exists(),
        ]);
    }

    protected function recipient(): User|Admin
    {
        $student = Auth::guard('web')->user();
        abort_unless($student instanceof User, 403);

        return $student;
    }

    private function markUnreadAsRead(User|Admin $recipient, HasMany $notifications, ?int $id = null): void
    {
        $updated = $notifications->whereNull('read_at')->update([
            'read_at' => $notifications->getRelated()->fromDateTime(now()),
        ]);

        if ($updated > 0) {
            NotificationChanged::dispatch($recipient instanceof Admin ? 'admin' : 'user', $recipient->id, $id);
        }

        $this->dispatch('gigkampus:counts');
    }
}
