<?php

namespace App\Services\AI;

use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\VectorStore\DistanceMetric;

/**
 * Identitas penyedia/model yang menentukan ruang vektor.
 *
 * Identitas administratif (configuration_id/revision_id) tidak dipakai sebagai
 * bukti kompatibilitas: dua revisi berbeda dapat menunjuk penyedia yang sama,
 * dan satu revisi dapat memakai endpoint yang berubah. Yang menentukan adalah
 * driver, endpoint, model, dimensi, metrik, dan parameter generasi yang memang
 * mempengaruhi keluaran embedding. Credential sengaja tidak termasuk agar
 * rotasi atau kedaluwarsa kunci tidak mengubah identitas ruang vektor.
 */
final readonly class EmbeddingProvenance
{
    /** @param array<string, mixed>|null $generationParameters */
    public function __construct(
        public string $driver,
        public string $modelId,
        public int $dimension,
        public string $distanceMetric,
        public ?string $endpoint,
        public ?array $generationParameters,
    ) {}

    public static function forRevision(AiProviderConfigurationRevision $revision): self
    {
        return new self(
            (string) $revision->driver,
            (string) $revision->model_id,
            (int) ($revision->tested_embedding_dimensions ?? EmbeddingRevisionResolver::DIMENSION),
            DistanceMetric::Cosine->value,
            $revision->base_url !== null ? (string) $revision->base_url : null,
            $revision->generation_parameters,
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', EmbeddingEvidenceCodec::canonicalJson([
            'driver' => $this->driver,
            'endpoint' => $this->endpoint,
            'model_id' => $this->modelId,
            'dimension' => $this->dimension,
            'distance_metric' => $this->distanceMetric,
            'generation_parameters' => $this->generationParameters,
        ]));
    }
}
