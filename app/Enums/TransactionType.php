<?php

namespace App\Enums;

enum TransactionType: string
{
    case DirectGig = 'direct_gig';
    case CustomRequest = 'custom_request';
}
