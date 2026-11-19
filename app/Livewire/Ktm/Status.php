<?php

namespace App\Livewire\Ktm;

use App\Enums\KtmVerificationStatus;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class Status extends Component
{
    public function render(): View
    {
        $student = Auth::guard('web')->user();
        abort_unless($student instanceof User, 403);

        $history = $student->ktmVerificationRequests()->latest('submitted_at')->latest('id')->get();
        $current = $history->first();
        $canUpload = ! $student->is_verified && ! $student->is_suspended
            && ! $history->contains(fn ($verification) => in_array(
                $verification->status, [KtmVerificationStatus::Processing, KtmVerificationStatus::PendingReview], true
            ));

        return view('livewire.ktm.status', compact('student', 'history', 'current', 'canUpload'));
    }
}
