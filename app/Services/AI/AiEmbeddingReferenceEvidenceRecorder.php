<?php

namespace App\Services\AI;

use App\Models\AiEmbeddingReferenceEvidence;
use App\Models\AiProviderConfigurationRevision;
use RuntimeException;

/**
 * Membuat bukti referensi dari satu revisi embedding yang valid.
 *
 * Bukti ditulis sekali dan tidak pernah diperbarui; fingerprint yang sama
 * ditolak karena vektor acuan yang berbeda untuk provenance identik justru
 * menandakan penyedia tidak deterministik, bukan bukti yang boleh diganti.
 * Keluaran probe disimpan tanpa credential, sehingga kunci API yang kemudian
 * kedaluwarsa atau dirotasi tidak menghapus bukti yang sudah tersimpan.
 */
final class AiEmbeddingReferenceEvidenceRecorder
{
    private const SUPPORTED_DRIVER = 'openai-compatible';

    public function __construct(private readonly SecureEmbeddingClient $embeddingClient) {}

    public function record(AiProviderConfigurationRevision $revision): AiEmbeddingReferenceEvidence
    {
        $this->assertRecordableRevision($revision);

        $provenance = EmbeddingProvenance::forRevision($revision);
        $outputs = $this->embeddingClient->embed($revision, EmbeddingProbeProfile::texts());
        $probeOutputs = [];

        foreach (EmbeddingProbeProfile::keys() as $index => $key) {
            $vector = $outputs[$index] ?? null;

            if (! EmbeddingVectorComparator::isValidVector($vector, $provenance->dimension)) {
                throw new RuntimeException('Embedding provider returned an invalid response.');
            }

            $probeOutputs[$key] = $vector;
        }

        $attributes = EmbeddingEvidenceCodec::seal([
            'probe_profile' => EmbeddingProbeProfile::PROFILE,
            'probe_profile_version' => EmbeddingProbeProfile::VERSION,
            'provenance_fingerprint' => $provenance->fingerprint(),
            'driver' => $provenance->driver,
            'endpoint' => $provenance->endpoint,
            'model_id' => $provenance->modelId,
            'dimension' => $provenance->dimension,
            'distance_metric' => $provenance->distanceMetric,
            'generation_parameters' => $provenance->generationParameters === null
                ? null
                : EmbeddingEvidenceCodec::canonicalJson($provenance->generationParameters),
            'source_configuration_id' => $revision->configuration_id,
            'source_revision_id' => $revision->id,
            'probe_outputs' => EmbeddingEvidenceCodec::encodeOutputs($probeOutputs),
            'generated_at' => now(),
        ]);

        if (AiEmbeddingReferenceEvidence::query()->where('reference_fingerprint', $attributes['reference_fingerprint'])->exists()) {
            throw new RuntimeException('Reference evidence already exists for this embedding provenance.');
        }

        return AiEmbeddingReferenceEvidence::query()->create($attributes);
    }

    /**
     * Hanya revisi dengan driver, model, endpoint, dan credential yang layak
     * boleh menghasilkan bukti; sisanya gagal tertutup sebelum I/O provider.
     */
    private function assertRecordableRevision(AiProviderConfigurationRevision $revision): void
    {
        if ($revision->driver !== self::SUPPORTED_DRIVER
            || $revision->model_id !== EmbeddingRevisionResolver::MODEL
            || blank($revision->base_url)
            || blank($revision->getRawOriginal('credential'))
        ) {
            throw new RuntimeException('No supported embedding revision is available for evidence generation.');
        }
    }
}
