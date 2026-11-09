<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderConfigurationRevision extends Model
{
    /**
     * Driver provider yang boleh dikonfigurasi melalui AI Settings. Daftar ini
     * juga menjadi batas validasi create/revise dan pilihan di form Admin.
     *
     * @var list<string>
     */
    public const SUPPORTED_DRIVERS = ['openai', 'openai-compatible', 'gemini', 'openrouter'];

    /** Driver default saat klien tidak mengirim pilihan eksplisit. */
    public const DEFAULT_DRIVER = 'openai-compatible';

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
