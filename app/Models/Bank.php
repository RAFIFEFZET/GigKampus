<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    protected $table = 'banks';

    public $timestamps = false;

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'bank_id');
    }
}
