<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmSchedulePauseInterval extends Model
{
    protected $fillable = ['pm_schedule_id', 'paused_on', 'resumed_on'];

    protected function casts(): array
    {
        return [
            'paused_on' => 'date',
            'resumed_on' => 'date',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PmSchedule::class);
    }
}
