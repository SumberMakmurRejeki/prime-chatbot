<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProviderConfiguration extends Model
{
    protected $fillable = ['display_name', 'purpose'];

    protected $hidden = ['active_revision_id', 'previous_known_good_revision_id'];

    public function revisions(): HasMany
    {
        return $this->hasMany(AiProviderConfigurationRevision::class, 'configuration_id');
    }

    public function activeRevision(): BelongsTo
    {
        return $this->belongsTo(AiProviderConfigurationRevision::class, 'active_revision_id');
    }

    public function previousKnownGoodRevision(): BelongsTo
    {
        return $this->belongsTo(AiProviderConfigurationRevision::class, 'previous_known_good_revision_id');
    }
}
