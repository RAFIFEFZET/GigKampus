<?php

namespace App\Enums;

enum ReportCategory: string
{
    case AcademicIntegrity = 'academic_integrity';
    case IllegalDangerous = 'illegal_dangerous';
    case CopyrightPrivacy = 'copyright_privacy';
    case MisleadingSpam = 'misleading_spam';
    case TransactionIssue = 'transaction_issue';
    case HarassmentSara = 'harassment_sara';
    case IdentityFeatureAbuse = 'identity_feature_abuse';
}
