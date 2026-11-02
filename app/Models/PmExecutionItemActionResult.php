<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmExecutionItemActionResult extends Model
{
    protected $fillable = [
        'pm_execution_item_id',
        'selected_label_snapshot',
        'option_position_snapshot',
        'selected_label_identity_hash',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PmExecutionItem::class, 'pm_execution_item_id');
    }
}
