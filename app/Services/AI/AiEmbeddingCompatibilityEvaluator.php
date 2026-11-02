<?php

namespace App\Services\AI;

use App\Models\AiEmbeddingReferenceEvidence;
use App\Models\AiProviderConfigurationRevision;
use Throwable;

/**
 * Menilai apakah revisi embedding kandidat kompatibel dengan bukti referensi.
 *
 * Ketidakcocokan struktural (model, dimensi, metrik, parameter) langsung
 * INCOMPATIBLE tanpa I/O provider. Endpoint atau driver yang berbeda hanya
 * UNKNOWN: kesamaan nama model dan dimensi tidak membuktikan kesetaraan model
 * upstream, jadi tanpa bukti provenance penuh tidak boleh COMPATIBLE. Kegagalan
 * provider dan bukti yang hilang/rusak juga UNKNOWN; hanya vektor probe yang
 * cocok dalam toleransi dengan provenance identik yang COMPATIBLE.
 */
final class AiEmbeddingCompatibilityEvaluator
{
    public function __construct(private readonly SecureEmbeddingClient $embeddingClient) {}

    public function evaluate(AiProviderConfigurationRevision $candidate, ?AiEmbeddingReferenceEvidence $reference): EmbeddingCompatibilityResult
    {
        if ($reference === null) {
            return EmbeddingCompatibilityResult::unknown('reference_missing', 'No persisted reference evidence exists for comparison.');
        }

        if (! $reference->isIntegrityValid()) {
            return EmbeddingCompatibilityResult::unknown('reference_untrusted', 'Persisted reference evidence failed integrity verification.');
        }

        if ($reference->probe_profile !== EmbeddingProbeProfile::PROFILE
            || (int) $reference->probe_profile_version !== EmbeddingProbeProfile::VERSION
        ) {
            return EmbeddingCompatibilityResult::unknown('probe_profile_unsupported', 'Reference evidence was recorded with a different probe profile.');
        }

        $outcome = $this->structuralOutcome($candidate, $reference);

        if ($outcome !== null) {
            return $outcome;
        }

        $outputs = $reference->decodedProbeOutputs();
        $expectedKeys = EmbeddingProbeProfile::keys();
        $sortedExpectedKeys = $expectedKeys;
        $sortedStoredKeys = is_array($outputs) ? array_keys($outputs) : [];
        sort($sortedExpectedKeys);
        sort($sortedStoredKeys);

        if ($outputs === null || $sortedStoredKeys !== $sortedExpectedKeys) {
            return EmbeddingCompatibilityResult::unknown('reference_untrusted', 'Reference evidence probe outputs do not match the probe profile.');
        }

        $dimension = (int) $reference->dimension;

        foreach ($outputs as $vector) {
            if (! EmbeddingVectorComparator::isValidVector($vector, $dimension)) {
                return EmbeddingCompatibilityResult::unknown('reference_untrusted', 'Reference evidence contains structurally invalid vectors.');
            }
        }

        try {
            $candidateOutputs = $this->embeddingClient->embed($candidate, EmbeddingProbeProfile::texts());
        } catch (Throwable $exception) {
            if ($exception instanceof \RuntimeException && $exception->getMessage() === 'Embedding provider returned an invalid response.') {
                return EmbeddingCompatibilityResult::unknown('candidate_response_invalid', 'Embedding provider response failed structural validation.');
            }

            return EmbeddingCompatibilityResult::unknown('provider_failure', 'Embedding provider comparison could not be completed.');
        }

        if (count($candidateOutputs) !== count($expectedKeys)) {
            return EmbeddingCompatibilityResult::incompatible('vector_length_mismatch', 'Candidate probe vector count differs from the probe profile.');
        }

        // Keluaran kandidat mengikuti urutan probe profil, sedangkan payload
        // tersimpan diurutkan berdasarkan kunci; pasangan wajib direkonstruksi
        // per kunci probe agar vektor tidak pernah dibandingkan dengan input lain.
        foreach ($expectedKeys as $index => $key) {
            $divergence = EmbeddingVectorComparator::divergenceCode($outputs[$key], $candidateOutputs[$index] ?? []);

            if ($divergence !== null) {
                return EmbeddingCompatibilityResult::incompatible($divergence, sprintf('Probe "%s" diverged beyond the documented tolerance.', $key));
            }
        }

        return EmbeddingCompatibilityResult::compatible('All probe vectors match within the documented tolerance under verified provenance.');
    }

    private function structuralOutcome(AiProviderConfigurationRevision $candidate, AiEmbeddingReferenceEvidence $reference): ?EmbeddingCompatibilityResult
    {
        $provenance = EmbeddingProvenance::forRevision($candidate);

        if ($reference->model_id !== $provenance->modelId) {
            return EmbeddingCompatibilityResult::incompatible('model_mismatch', 'Reference model differs from the candidate model.');
        }

        if ((int) $reference->dimension !== $provenance->dimension) {
            return EmbeddingCompatibilityResult::incompatible('dimension_mismatch', 'Reference dimension differs from the candidate dimension.');
        }

        if ($reference->distance_metric !== $provenance->distanceMetric) {
            return EmbeddingCompatibilityResult::incompatible('distance_metric_mismatch', 'Reference distance metric differs from the candidate metric.');
        }

        $referenceParameters = $reference->generation_parameters === null
            ? null
            : EmbeddingEvidenceCodec::canonicalJson(
                json_decode((string) $reference->generation_parameters, true)
            );

        if ($referenceParameters !== ($provenance->generationParameters === null
            ? null
            : EmbeddingEvidenceCodec::canonicalJson($provenance->generationParameters))
        ) {
            return EmbeddingCompatibilityResult::incompatible('generation_parameters_mismatch', 'Reference generation parameters differ from the candidate parameters.');
        }

        if ($reference->driver !== $provenance->driver
            || $reference->endpoint !== $provenance->endpoint
            || (string) $reference->provenance_fingerprint !== $provenance->fingerprint()
        ) {
            return EmbeddingCompatibilityResult::unknown('provenance_unverified', 'Candidate provider provenance differs from the recorded reference provenance.');
        }

        return null;
    }
}
