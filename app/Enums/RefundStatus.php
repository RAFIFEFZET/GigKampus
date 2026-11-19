<?php

namespace App\Enums;

enum RefundStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
}
