<?php

namespace App\Services\AI;

/**
 * Hasil evaluasi kompatibilitas embedding beserta kode alasan yang stabil.
 *
 * Kode alasan adalah bagian dari kontrak mesin (audit dan pemanggil berikutnya),
 * jadi nilainya tidak boleh berubah hanya karena pesan detail diperhalus.
 *
 * COMPATIBLE:
 * - probe_match_verified_provenance
 *
 * INCOMPATIBLE:
 * - model_mismatch, dimension_mismatch, distance_metric_mismatch,
 *   generation_parameters_mismatch
 * - vector_length_mismatch, vector_value_divergence
 *
 * UNKNOWN:
 * - reference_missing, reference_untrusted, probe_profile_unsupported,
 *   provenance_unverified, provider_failure, candidate_response_invalid
 */
final readonly class EmbeddingCompatibilityResult
{
    public function __construct(
        public EmbeddingCompatibilityStatus $status,
        public string $reasonCode,
        public string $detail,
    ) {}

    public static function compatible(string $detail): self
    {
        return new self(EmbeddingCompatibilityStatus::Compatible, 'probe_match_verified_provenance', $detail);
    }

    public static function incompatible(string $reasonCode, string $detail): self
    {
        return new self(EmbeddingCompatibilityStatus::Incompatible, $reasonCode, $detail);
    }

    public static function unknown(string $reasonCode, string $detail): self
    {
        return new self(EmbeddingCompatibilityStatus::Unknown, $reasonCode, $detail);
    }
}
