<?php

namespace App\Livewire\Inbox;

use App\Models\Admin;
use Illuminate\Support\Facades\Auth;

class AdminNotifications extends Notifications
{
    protected function recipient(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
