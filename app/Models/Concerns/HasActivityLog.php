<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;

trait HasActivityLog
{
    public function activities()
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('id');
    }
}
