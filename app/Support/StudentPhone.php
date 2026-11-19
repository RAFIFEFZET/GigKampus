<?php

namespace App\Support;

final class StudentPhone
{
    public const RULE = 'regex:/\A\+62[0-9]{8,13}\z/';

    public static function normalize(mixed $number): mixed
    {
        if (! is_string($number)) {
            return $number;
        }

        $number = preg_replace('/[\s().-]+/u', '', $number);

        return str_starts_with($number ?? '', '08') ? '+62'.substr($number, 1) : $number;
    }
}
