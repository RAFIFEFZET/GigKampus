<?php

namespace App\Livewire\Inbox;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Notifications extends Component
{
    use WithPagination;

    public function markRead(int $id): void
    {
        $student = Auth::guard('web')->user();
        abort_unless($student, 403);

        $entry = $student->notifications()->findOrFail($id);
        $entry->markReadBy($student);
        $entry->save();
    }

    public function render(): View
    {
        $student = Auth::guard('web')->user();
        abort_unless($student, 403);

        return view('livewire.inbox.notifications', [
            'entries' => $student->notifications()->latest()->orderByDesc('id')->paginate(12),
        ]);
    }
}
