<?php

namespace App\Livewire\Orders;

use App\Enums\OrderStatus;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ValidationQueue extends Component
{
    use WithPagination;

    public function render(): View
    {
        $seller = Auth::guard('web')->user();
        abort_unless($seller instanceof User, 403);
        $orders = $seller->sellerOrders()->where('status', OrderStatus::AwaitingValidation)
            ->with(['buyer', 'seller', 'payment'])
            ->oldest('updated_at')->orderBy('id')->paginate(10);

        return view('livewire.orders.validation-queue', compact('seller', 'orders'));
    }
}
