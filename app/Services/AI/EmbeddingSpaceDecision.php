<?php

namespace App\Services\AI;

/**
 * Keputusan guard ruang vektor beserta kode alasan yang stabil.
 *
 * Objek ini adalah kontrak keputusan, bukan pesan UI: pemanggil berikutnya
 * (integrasi Activate/Restore) memetakan kode alasan ke bahasa manusia, jadi
 * nilai kode tidak boleh berubah hanya karena detail diperhalus.
 *
 * REUSE_INDEXED_SPACE:
 * - probe_match_verified_provenance (evaluator menyetujui probe kandidat)
 * - effective_space_mapping_verified (verifikasi pemetaan tersimpan, tanpa I/O provider)
 *
 * REJECT_REUSE (diteruskan apa adanya dari AiEmbeddingCompatibilityEvaluator):
 * - model_mismatch, dimension_mismatch, distance_metric_mismatch,
 *   generation_parameters_mismatch
 * - vector_length_mismatch, vector_value_divergence
 * - provenance_unverified, provider_failure, candidate_response_invalid
 *
 * REJECT_REUSE (khusus guard):
 * - reference_missing, reference_untrusted, distance_metric_unsupported,
 *   indexed_space_unknown, effective_space_unmapped, evidence_mismatch,
 *   indexed_space_mismatch, indexed_space_unattested, index_state_ambiguous

 * REQUIRE_FIRST_SPACE_BOOTSTRAP:
 * - first_space_bootstrap_required
 */
final readonly class EmbeddingSpaceDecision
{
    public function __construct(
        public EmbeddingSpaceDecisionStatus $status,
        public string $reasonCode,
        public string $detail,
        public ?string $effectiveSpaceIdentity,
    ) {}

    /**
     * Identitas ruang disimpan apa adanya: pemakaian ulang tidak boleh
     * menulis ulang identitas titik Qdrant yang sudah terindeks. Kode alasan
     * berbeda antara jalur evaluator (probe) dan jalur verifikasi offline.
     */
    public static function reuse(string $reasonCode, string $effectiveSpaceIdentity, string $detail): self
    {
        return new self(EmbeddingSpaceDecisionStatus::ReuseIndexedSpace, $reasonCode, $detail, $effectiveSpaceIdentity);
    }

    public static function reject(string $reasonCode, string $detail): self
    {
        return new self(EmbeddingSpaceDecisionStatus::RejectReuse, $reasonCode, $detail, null);
    }

    public static function bootstrapRequired(string $detail): self
    {
        return new self(EmbeddingSpaceDecisionStatus::RequireFirstSpaceBootstrap, 'first_space_bootstrap_required', $detail, null);
    }
}
