<?php

namespace App\Enums;

enum ReportType: string
{
    case GigReport = 'gig_report';
    case RequestReport = 'request_report';
    case ReviewReport = 'review_report';
    case ChatReport = 'chat_report';
    case OrderComplaint = 'order_complaint';
}
