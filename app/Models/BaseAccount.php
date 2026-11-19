<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Foundation\Auth\User as Authenticatable;

abstract class BaseAccount extends Authenticatable
{
    use HasPreciseTimestamps;

    protected $authPasswordName = 'password_hash';

    protected $rememberTokenName = null;

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function hasId(?int $id): bool
    {
        return $id !== null && $this->getKey() === $id;
    }
}
