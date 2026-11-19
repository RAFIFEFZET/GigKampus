<?php

namespace App\Enums;

enum PaymentProofStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
