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

    public function label(): string
    {
        return match ($this) {
            self::AcademicIntegrity => 'Integritas akademik',
            self::IllegalDangerous => 'Ilegal, asusila, berbahaya',
            self::CopyrightPrivacy => 'Hak cipta dan privasi',
            self::MisleadingSpam => 'Fiktif, menyesatkan, spam',
            self::TransactionIssue => 'Masalah transaksi atau dugaan manipulasi',
            self::HarassmentSara => 'Pelecehan dan SARA',
            self::IdentityFeatureAbuse => 'Identitas dan penyalahgunaan fitur',
        };
    }
}
