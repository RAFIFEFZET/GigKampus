<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<int, int|string>
 */
final class WholeRupiah implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int|string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return $value;
        }

        throw new InvalidArgumentException("The {$key} amount must be an integer number of rupiah.");
    }
}
