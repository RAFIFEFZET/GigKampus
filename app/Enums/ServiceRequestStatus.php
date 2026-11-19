<?php

namespace App\Enums;

enum ServiceRequestStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Ordered = 'ordered';
}
