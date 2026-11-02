<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderConfigurationRevision extends Model
{
    protected $fillable = [
        'driver', 'model_id', 'base_url', 'credential', 'generation_parameters', 'enabled',
    ];

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'generation_parameters' => 'array',
            'enabled' => 'boolean',
            'test_started_at' => 'immutable_datetime',
            'tested_at' => 'immutable_datetime',
        ];
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(AiProviderConfiguration::class, 'configuration_id');
    }
}
