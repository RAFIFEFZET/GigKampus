<?php

namespace App\Contracts;

use App\Models\Gig;
use App\Models\Order;

interface BuyerInterface
{
    public function canCreateNewTransaction(): bool;

    public function canBuyGig(Gig $gig): bool;

    public function canAccessExistingOrder(Order $order): bool;
}
