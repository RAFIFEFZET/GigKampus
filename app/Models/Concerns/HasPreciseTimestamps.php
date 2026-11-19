<?php

namespace App\Models\Concerns;

trait HasPreciseTimestamps
{
    // Preserve both the offset and the microseconds when writing TIMESTAMPTZ.
    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
