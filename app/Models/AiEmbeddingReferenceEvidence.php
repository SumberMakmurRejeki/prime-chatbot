<?php

namespace App\Models;

use App\Services\AI\EmbeddingEvidenceCodec;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Referensi probe yang hanya dapat dibuat sekali dan tidak pernah diperbarui. */
class AiEmbeddingReferenceEvidence extends Model
{
    protected $table = 'ai_embedding_reference_evidences';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'probe_profile_version' => 'integer',
            'dimension' => 'integer',
            'source_configuration_id' => 'integer',
            'source_revision_id' => 'integer',
            'generated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Bukti adalah snapshot immutable; update/delete dilarang di jalur Eloquent.
     * Insert ulang untuk fingerprint sama ditolak database oleh UNIQUE.
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Embedding reference evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Embedding reference evidence is immutable.');
        });
    }

    public function isIntegrityValid(): bool
    {
        return EmbeddingEvidenceCodec::isSealed($this->attributesToArray());
    }

    /** @return array<string, list<int|float>>|null */
    public function decodedProbeOutputs(): ?array
    {
        return EmbeddingEvidenceCodec::decodeOutputs((string) $this->probe_outputs);
    }
}
