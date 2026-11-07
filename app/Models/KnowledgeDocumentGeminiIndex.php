<?php

namespace App\Models;

use App\Services\AI\GeminiDocumentIndexState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeDocumentGeminiIndex extends Model
{
    protected $table = 'knowledge_document_gemini_indexings';

    protected $fillable = [
        'knowledge_document_id', 'document_code', 'document_version', 'document_checksum',
        'store_id', 'google_file_id', 'operation_name', 'google_document_name', 'state',
        'generation', 'safe_error_code', 'safe_error_message', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => GeminiDocumentIndexState::class,
            'generation' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function knowledgeDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class);
    }
}
