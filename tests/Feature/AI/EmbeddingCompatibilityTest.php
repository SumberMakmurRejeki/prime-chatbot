<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\AiEmbeddingCompatibilityEvaluator;
use App\Services\AI\AiEmbeddingReferenceEvidenceRecorder;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\EmbeddingCompatibilityStatus;
use App\Services\AI\EmbeddingEvidenceCodec;
use App\Services\AI\EmbeddingProbeProfile;
use App\Services\AI\EmbeddingRevisionResolver;
use App\Services\AI\EmbeddingVectorComparator;
use App\Services\AI\SecureEmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EmbeddingCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_evidence_is_persisted_without_credentials_and_cannot_be_changed(): void
    {
        $revision = $this->revision();
        $this->fakeEmbeddingResponse();

        $evidence = $this->recorder()->record($revision);

        $this->assertSame(EmbeddingProbeProfile::PROFILE, $evidence->probe_profile);
        $this->assertSame(EmbeddingProbeProfile::VERSION, $evidence->probe_profile_version);
        $this->assertTrue($evidence->isIntegrityValid());
        $this->assertCount(count(EmbeddingProbeProfile::probes()), $evidence->decodedProbeOutputs());
        $this->assertStringNotContainsString('test-secret', (string) $evidence->probe_outputs);

        $this->expectException(LogicException::class);
        $evidence->model_id = 'tampered';
        $evidence->save();
    }

    public function test_same_provenance_and_probe_vectors_are_compatible_even_after_old_credential_is_gone(): void
    {
        $referenceRevision = $this->revision();
        $this->fakeEmbeddingResponse();
        $evidence = $this->recorder()->record($referenceRevision);
        $referenceRevision->credential = null;
        $referenceRevision->save();

        $candidate = $this->revision(['credential' => 'replacement-secret']);
        $this->fakeEmbeddingResponse();

        $result = $this->evaluator()->evaluate($candidate, $evidence);

        $this->assertSame(EmbeddingCompatibilityStatus::Compatible, $result->status, $result->reasonCode.': '.$result->detail);
        $this->assertSame('probe_match_verified_provenance', $result->reasonCode);
    }

    public function test_matching_model_and_dimension_with_changed_endpoint_is_unknown(): void
    {
        $referenceRevision = $this->revision();
        $this->fakeEmbeddingResponse();
        $evidence = $this->recorder()->record($referenceRevision);
        $candidate = $this->revision(['base_url' => 'https://other-provider.example.test/v1']);
        Http::fake([
            'https://other-provider.example.test/*' => Http::response('', 500),
            'https://provider.example.test/*' => Http::response('', 500),
        ]);
        $result = $this->evaluator()->evaluate($candidate, $evidence);

        $this->assertSame(EmbeddingCompatibilityStatus::Unknown, $result->status);
        $this->assertSame('provenance_unverified', $result->reasonCode);
        Http::assertNothingSent();
    }

    public function test_missing_reference_and_corrupted_checksum_are_unknown(): void
    {
        $candidate = $this->revision();
        $evaluator = $this->evaluator();

        $this->assertSame('reference_missing', $evaluator->evaluate($candidate, null)->reasonCode);

        $this->fakeEmbeddingResponse();
        $evidence = $this->recorder()->record($candidate);
        $evidence->probe_outputs = str_replace('0.125', '0.126', $evidence->probe_outputs);

        $result = $evaluator->evaluate($candidate, $evidence);

        $this->assertSame(EmbeddingCompatibilityStatus::Unknown, $result->status);
        $this->assertSame('reference_untrusted', $result->reasonCode);
    }

    public function test_model_and_dimension_mismatch_are_incompatible_without_provider_io(): void
    {
        $revision = $this->revision();
        $this->fakeEmbeddingResponse();
        $evidence = $this->recorder()->record($revision);
        $modelMismatch = $this->revision(['model_id' => 'another-model']);
        $dimensionMismatch = $this->revision(['tested_embedding_dimensions' => 1536]);
        $modelEvidence = $evidence;
        $dimensionEvidence = $evidence;

        // Fake dibuat ulang agar rekaman permintaan perekaman bukti dibersihkan;
        // evaluator struktural tidak boleh memanggil provider sama sekali.
        Http::fake(['https://provider.example.test/*' => Http::response('', 500)]);

        $this->assertSame('model_mismatch', $this->evaluator()->evaluate($modelMismatch, $modelEvidence)->reasonCode);
        $this->assertSame('dimension_mismatch', $this->evaluator()->evaluate($dimensionMismatch, $dimensionEvidence)->reasonCode);
        Http::assertNothingSent();
    }

    public function test_divergent_probe_vectors_are_incompatible(): void
    {
        $revision = $this->revision();
        $this->fakeEmbeddingSequence(
            $this->payloadFor(['0.125', '0.25', '0.375', '0.5']),
            $this->payloadFor(['0.125', '0.1249', '0.375', '0.5']),
        );
        $evidence = $this->recorder()->record($revision);
        $result = $this->evaluator()->evaluate($revision, $evidence);

        $this->assertSame(EmbeddingCompatibilityStatus::Incompatible, $result->status);
        $this->assertSame('vector_value_divergence', $result->reasonCode);
    }

    public function test_structurally_invalid_candidate_response_is_unknown(): void
    {
        $revision = $this->revision();
        $invalidVector = implode(',', array_fill(0, EmbeddingRevisionResolver::DIMENSION - 1, '0.5'));
        $invalidPayload = '{"data":['.implode(',', array_map(
            static fn (int $index): string => '{"index":'.$index.',"embedding":['.$invalidVector.']}',
            range(0, 3),
        )).']}';
        $this->fakeEmbeddingSequence(
            $this->payloadFor(['0.125', '0.25', '0.375', '0.5']),
            $invalidPayload,
        );
        $evidence = $this->recorder()->record($revision);
        $result = $this->evaluator()->evaluate($revision, $evidence);

        $this->assertSame(EmbeddingCompatibilityStatus::Unknown, $result->status);
        $this->assertSame('candidate_response_invalid', $result->reasonCode);
    }

    public function test_non_finite_candidate_response_is_unknown_at_the_public_boundary(): void
    {
        $revision = $this->revision();
        $this->fakeEmbeddingSequence(
            $this->payloadFor(['0.125', '0.25', '0.375', '0.5']),
            $this->payloadFor(['0.125', '1e999', '0.375', '0.5']),
        );
        $evidence = $this->recorder()->record($revision);
        $result = $this->evaluator()->evaluate($revision, $evidence);

        $this->assertNotSame(EmbeddingCompatibilityStatus::Compatible, $result->status);
        $this->assertSame(EmbeddingCompatibilityStatus::Unknown, $result->status);
        $this->assertSame('candidate_response_invalid', $result->reasonCode);
    }

    public function test_duplicate_recording_and_deletion_are_refused(): void
    {
        $revision = $this->revision();
        $this->fakeEmbeddingResponse();
        $evidence = $this->recorder()->record($revision);

        try {
            $this->recorder()->record($revision);
            $this->fail('Duplicate reference evidence must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Reference evidence already exists for this embedding provenance.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $evidence->delete();
    }

    public function test_vector_comparison_rejects_invalid_values_and_only_accepts_bounded_differences(): void
    {
        $reference = [0.25, -0.5, 1.0];
        $withinTolerance = [0.2500001, -0.5, 1.0];
        $outsideTolerance = [0.25001, -0.5, 1.0];

        $this->assertTrue(EmbeddingVectorComparator::isValidVector($reference, 3));
        $this->assertFalse(EmbeddingVectorComparator::isValidVector([0.25, INF, 1.0], 3));
        $this->assertFalse(EmbeddingVectorComparator::isValidVector([0.25, -0.5], 3));
        $this->assertNull(EmbeddingVectorComparator::divergenceCode($reference, $withinTolerance));
        $this->assertSame('vector_value_divergence', EmbeddingVectorComparator::divergenceCode($reference, $outsideTolerance));
    }

    /** @return array<string, array{0: list<int|float>, 1: array, 2: string|null}> */
    public static function invalidVectorProvider(): array
    {
        return [
            'NaN kandidat' => [[0.15, 0.25, 0.35], [0.15, NAN, 0.35], 'vector_value_divergence'],
            'NaN referensi' => [[0.15, NAN, 0.35], [0.15, 0.25, 0.35], 'vector_value_divergence'],
            'NaN kedua vektor' => [[0.15, NAN, 0.35], [0.15, NAN, 0.35], 'vector_value_divergence'],
            'INF kandidat' => [[0.15, 0.25, 0.35], [0.15, INF, 0.35], 'vector_value_divergence'],
            '-INF kandidat' => [[0.15, 0.25, 0.35], [0.15, -INF, 0.35], 'vector_value_divergence'],
            'INF referensi' => [[0.15, INF, 0.35], [0.15, 0.25, 0.35], 'vector_value_divergence'],
            '-INF referensi' => [[0.15, -INF, 0.35], [0.15, 0.25, 0.35], 'vector_value_divergence'],
            'INF kedua vektor' => [[0.15, INF, 0.35], [0.15, INF, 0.35], 'vector_value_divergence'],
            'komponen non-numerik kandidat' => [[0.15, 0.25, 0.35], [0.15, '0.25', 0.35], 'vector_value_divergence'],
            'kandidat tidak ber-list' => [[0.15, 0.25, 0.35], [1 => 0.15, 2 => 0.25, 3 => 0.35], 'vector_value_divergence'],
            'dimensi tidak sama' => [[0.15, 0.25], [0.15, 0.25, 0.35], 'vector_length_mismatch'],
            'vektor kosong' => [[], [], null],
        ];
    }

    #[DataProvider('invalidVectorProvider')]
    public function test_malformed_and_non_finite_vectors_never_match(
        array $reference,
        array $candidate,
        ?string $expectedCode,
    ): void {
        $this->assertSame($expectedCode, EmbeddingVectorComparator::divergenceCode($reference, $candidate));
    }

    public function test_valid_identical_within_tolerance_and_zero_vectors_match(): void
    {
        // Vektor nol tetap sah pada kontrak saat ini; tidak ada aturan domain baru.
        $this->assertTrue(EmbeddingVectorComparator::isValidVector([0.0, 0.0], 2));
        $this->assertNull(EmbeddingVectorComparator::divergenceCode([0.0, 0.0], [0.0, 0.0]));
        $this->assertNull(EmbeddingVectorComparator::divergenceCode([0.25, -0.5], [0.25, -0.5]));
        $this->assertNull(EmbeddingVectorComparator::divergenceCode([0.25, -0.5], [0.2500001, -0.5]));
        $this->assertSame('vector_value_divergence', EmbeddingVectorComparator::divergenceCode([0.25, -0.5], [0.25001, -0.5]));
    }

    public function test_evidence_integrity_covers_provenance_and_legacy_vector_identity_is_unchanged(): void
    {
        $identity = (new EmbeddingRevisionResolver)->space($this->revision())->identity;

        $this->assertSame('rag:config-1:revision-1:text-embedding-3-large:3072:cosine', $identity);

        $attributes = EmbeddingEvidenceCodec::seal([
            'probe_profile' => EmbeddingProbeProfile::PROFILE,
            'probe_profile_version' => EmbeddingProbeProfile::VERSION,
            'provenance_fingerprint' => str_repeat('a', 64),
            'driver' => 'openai-compatible',
            'endpoint' => 'https://provider.example.test/v1',
            'model_id' => 'text-embedding-3-large',
            'dimension' => 3072,
            'distance_metric' => 'Cosine',
            'generation_parameters' => null,
            'source_configuration_id' => 1,
            'source_revision_id' => 1,
            'probe_outputs' => '{"probe":[0.125]}',
        ]);

        $this->assertTrue(EmbeddingEvidenceCodec::isSealed($attributes));
        $attributes['endpoint'] = 'https://attacker.example.test';
        $this->assertFalse(EmbeddingEvidenceCodec::isSealed($attributes));
    }

    private function revision(array $overrides = []): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Embedding test',
            'purpose' => 'embedding',
        ]);
        $revision = new AiProviderConfigurationRevision;
        $revision->forceFill(array_merge([
            'configuration_id' => $configuration->id,
            'revision_number' => 1,
            'driver' => 'openai-compatible',
            'model_id' => 'text-embedding-3-large',
            'base_url' => 'https://provider.example.test/v1',
            'credential' => 'test-secret',
            'generation_parameters' => null,
            'enabled' => true,
            'test_state' => 'passed',
            'test_attempt_id' => 'attempt-1',
            'tested_at' => now(),
            'tested_embedding_dimensions' => 3072,
        ], $overrides))->save();

        return $revision;
    }

    private function recorder(): AiEmbeddingReferenceEvidenceRecorder
    {
        return new AiEmbeddingReferenceEvidenceRecorder($this->client());
    }

    private function evaluator(): AiEmbeddingCompatibilityEvaluator
    {
        return new AiEmbeddingCompatibilityEvaluator($this->client());
    }

    private function client(): SecureEmbeddingClient
    {
        $pinner = new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']);

        return new SecureEmbeddingClient($this->app, $pinner);
    }

    /** @param list<string> $payloads */
    private function fakeEmbeddingSequence(string ...$payloads): void
    {
        Http::fake(['provider.example.test/v1/embeddings' => function () use (&$payloads) {
            return Http::response(array_shift($payloads) ?? '');
        }]);
    }

    /** @param list<string> $vectorValues */
    private function payloadFor(array $vectorValues): string
    {
        return '{"data":['.implode(',', array_map(
            static fn (int $index, string $value): string => '{"index":'.$index.',"embedding":['
                .implode(',', array_fill(0, EmbeddingRevisionResolver::DIMENSION, $value)).']}',
            array_keys($vectorValues),
            $vectorValues,
        )).']}';
    }

    private function fakeEmbeddingResponse(): void
    {
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response($this->embeddingPayload()),
        ]);
    }

    private function embeddingPayload(): string
    {
        $valuesByProbe = array_combine(EmbeddingProbeProfile::texts(), ['0.125', '0.25', '0.375', '0.5']);
        $embeddings = array_map(static function (string $text, int $index) use ($valuesByProbe): string {
            $vector = implode(',', array_fill(0, EmbeddingRevisionResolver::DIMENSION, $valuesByProbe[$text]));

            return '{"index":'.$index.',"embedding":['.$vector.']}';
        }, EmbeddingProbeProfile::texts(), array_keys(EmbeddingProbeProfile::texts()));

        return '{"data":['.implode(',', $embeddings).']}';
    }
}
