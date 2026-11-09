<?php

namespace App\Models;

use Database\Factories\KnowledgeDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KnowledgeDocument extends Model
{
    /** @use HasFactory<KnowledgeDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'document_code', 'title', 'original_filename', 'version', 'checksum', 'status',
        'is_published', 'published_at', 'published_by', 'storage_path', 'file_size',
        'page_count', 'chunk_count', 'vector_space_identity', 'pipeline_fingerprint',
        'processed_at', 'safe_error_code', 'safe_error_message', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function geminiIndex(): HasOne
    {
        return $this->hasOne(KnowledgeDocumentGeminiIndex::class);
    }
}
