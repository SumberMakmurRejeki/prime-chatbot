<?php

namespace Tests\Feature\AI;

use App\Models\AiEmbeddingReferenceEvidence;
use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Services\AI\AiEmbeddingActivationCompatibilityGuard;
use App\Services\AI\AiEmbeddingCompatibilityEvaluator;
use App\Services\AI\AiEmbeddingReferenceEvidenceRecorder;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\EmbeddingIndexState;
use App\Services\AI\EmbeddingProbeProfile;
use App\Services\AI\EmbeddingRevisionResolver;
use App\Services\AI\EmbeddingSpaceDecisionStatus;
use App\Services\AI\SecureEmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guard keputusan ruang vektor Slice 2A: pemakaian ulang ruang terindeks,
 * penolakan fail-closed, batas bootstrap, dan invarian pemetaan durable.
 * Semua bukti disintesis di SQLite :memory: tanpa provider atau Qdrant nyata.
 */
class AiEmbeddingActivationCompatibilityGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Identitas ruang historis (bentuk FNC-01); guard tidak boleh menulis ulang. */
    private const LEGACY_SPACE_IDENTITY = 'rag:config-1:revision-1:text-embedding-3-large:3072:cosine';

    public function test_compatible_candidate_reuses_recorded_indexed_space_identity_without_moving_pointers(): void
    {
        $source = $this->revision();
        $sourceConfiguration = AiProviderConfiguration::query()->findOrFail($source->configuration_id);
        $sourceConfiguration->forceFill(['active_revision_id' => $source->id])->save();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), $this->embeddingPayload());
        $evidence = $this->recorder()->record($source);
        $this->indexedDocument(self::LEGACY_SPACE_IDENTITY);

        $candidate = $this->revision();
        $this->mapSpace($candidate, self::LEGACY_SPACE_IDENTITY, $evidence);

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Populated);

        $this->assertSame(EmbeddingSpaceDecisionStatus::ReuseIndexedSpace, $decision->status);
        $this->assertSame('probe_match_verified_provenance', $decision->reasonCode);
        $this->assertSame(self::LEGACY_SPACE_IDENTITY, $decision->effectiveSpaceIdentity);
        $this->assertSame($source->id, (int) $sourceConfiguration->fresh()->active_revision_id);
        $this->assertNull(AiProviderConfiguration::query()->findOrFail($candidate->configuration_id)->getRawOriginal('active_revision_id'));
        Http::assertSentCount(2);
    }

    public function test_divergent_probe_vectors_reject_reuse_and_keep_evaluator_reason_code(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), $this->payloadFor(['0.125', '0.1249', '0.375', '0.5']));
        $evidence = $this->recorder()->record($source);
        $this->indexedDocument(self::LEGACY_SPACE_IDENTITY);

        $candidate = $this->revision();
        $this->mapSpace($candidate, self::LEGACY_SPACE_IDENTITY, $evidence);

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Populated);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('vector_value_divergence', $decision->reasonCode);
        $this->assertNull($decision->effectiveSpaceIdentity);
    }

    public function test_provider_failure_rejects_reuse_without_claiming_incompatibility(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), '');
        $evidence = $this->recorder()->record($source);
        $this->indexedDocument(self::LEGACY_SPACE_IDENTITY);

        $candidate = $this->revision();
        $this->mapSpace($candidate, self::LEGACY_SPACE_IDENTITY, $evidence);

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Populated);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('provider_failure', $decision->reasonCode);
    }

    public function test_missing_legacy_reference_evidence_rejects_reuse_and_is_never_bootstrap(): void
    {
        // FNC-01 lama tidak memiliki bukti probe tepercaya: tetap ditolak,
        // tidak direklasifikasi menjadi kompatibel maupun bootstrap.
        Http::preventStrayRequests();
        $candidate = $this->revision();

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Populated);
        $verification = $this->guard()->verifyEffectiveSpaceMapping($candidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('reference_missing', $decision->reasonCode);
        $this->assertSame('reference_missing', $verification->reasonCode);
        Http::assertNothingSent();
    }

    public function test_directly_tampered_evidence_rejects_reuse_and_verification(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload());
        $evidence = $this->recorder()->record($source);

        $candidate = $this->revision();
        $this->mapSpace($candidate, self::LEGACY_SPACE_IDENTITY, $evidence);

        DB::table('ai_embedding_reference_evidences')
            ->where('id', $evidence->id)
            ->update(['probe_outputs' => str_replace('0.125', '0.126', (string) $evidence->probe_outputs)]);

        $this->assertSame('reference_untrusted', $this->guard()->decide($candidate, EmbeddingIndexState::Populated)->reasonCode);
        $this->assertSame('reference_untrusted', $this->guard()->verifyEffectiveSpaceMapping($candidate)->reasonCode);
        Http::assertSentCount(1);
    }

    public function test_changed_provider_provenance_rejects_reuse_without_provider_io(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload());
        $evidence = $this->recorder()->record($source);

        $candidate = $this->revision(['base_url' => 'https://other-provider.example.test/v1']);
        $this->mapSpace($candidate, self::LEGACY_SPACE_IDENTITY, $evidence);

        // Bukti dikunci per fingerprint provenance: endpoint berbeda berarti
        // tidak ada bukti tepercaya untuk kandidat ini, tanpa memanggil provider.
        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Populated);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('reference_missing', $decision->reasonCode);
        Http::assertSentCount(1);
    }

    public function test_mapping_with_different_identity_rejects_reuse_without_provider_io(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload());
        $evidence = $this->recorder()->record($source);

        $candidate = $this->revision();
        $this->mapSpace($candidate, 'rag:config-1:revision-1:text-embedding-3-large:1536:cosine', $evidence);

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Populated);

        $this->assertSame('indexed_space_mismatch', $decision->reasonCode);
        Http::assertSentCount(1);
    }

    public function test_mapping_citing_other_evidence_rejects_reuse_without_provider_io(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), $this->embeddingPayload());
        $this->recorder()->record($source);
        $foreignEvidence = $this->recorder()->record(
            $this->revision(['generation_parameters' => ['dimensions' => EmbeddingRevisionResolver::DIMENSION]]),
        );

        $candidate = $this->revision();
        $candidate->forceFill([
            'effective_vector_space_identity' => self::LEGACY_SPACE_IDENTITY,
            'effective_vector_space_evidence_id' => $foreignEvidence->id,
        ])->save();

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Populated);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('evidence_mismatch', $decision->reasonCode);
        Http::assertSentCount(2);
    }

    public function test_unmapped_populated_candidate_rejects_reuse_without_provider_io(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload());
        $this->recorder()->record($source);

        $candidate = $this->revision();

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Populated);

        $this->assertSame('effective_space_unmapped', $decision->reasonCode);
        Http::assertSentCount(1);
    }

    public function test_proven_empty_index_state_requires_separate_bootstrap_decision(): void
    {
        Http::preventStrayRequests();
        $candidate = $this->revision();

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::ProvenEmpty);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RequireFirstSpaceBootstrap, $decision->status);
        $this->assertSame('first_space_bootstrap_required', $decision->reasonCode);
        $this->assertNull($decision->effectiveSpaceIdentity);
        Http::assertNothingSent();
    }

    public function test_proven_empty_index_state_contradicting_a_mapping_is_ambiguous(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload());
        $evidence = $this->recorder()->record($source);

        $candidate = $this->revision();
        $this->mapSpace($candidate, self::LEGACY_SPACE_IDENTITY, $evidence);

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::ProvenEmpty);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('index_state_ambiguous', $decision->reasonCode);
    }

    public function test_ambiguous_index_state_rejects_reuse_without_provider_io(): void
    {
        Http::preventStrayRequests();
        $candidate = $this->revision();

        $decision = $this->guard()->decide($candidate, EmbeddingIndexState::Ambiguous);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('index_state_ambiguous', $decision->reasonCode);
        Http::assertNothingSent();
    }

    public function test_recording_mapping_persists_original_identity_once_and_is_idempotent(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), $this->embeddingPayload(), $this->embeddingPayload());
        $evidence = $this->recorder()->record($source);
        $this->indexedDocument(self::LEGACY_SPACE_IDENTITY);

        $firstCandidate = $this->revision();
        $recorded = $this->guard()->recordEffectiveSpaceMapping($firstCandidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::ReuseIndexedSpace, $recorded->status);
        $this->assertSame('effective_space_mapping_verified', $recorded->reasonCode);
        $this->assertSame(self::LEGACY_SPACE_IDENTITY, $recorded->effectiveSpaceIdentity);
        $this->assertSame(self::LEGACY_SPACE_IDENTITY, $firstCandidate->fresh()->effective_vector_space_identity);

        // Revisi lain dengan provenance identik memakai bukti dan ruang asal yang
        // sama; identitas administratifnya sendiri tidak pernah dipakai.
        $secondCandidate = $this->revision();
        $secondMapping = $this->guard()->recordEffectiveSpaceMapping($secondCandidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::ReuseIndexedSpace, $secondMapping->status);
        $this->assertSame(self::LEGACY_SPACE_IDENTITY, $secondMapping->effectiveSpaceIdentity);
        $this->assertSame(self::LEGACY_SPACE_IDENTITY, $secondCandidate->fresh()->effective_vector_space_identity);
        $this->assertSame(2, AiProviderConfigurationRevision::query()
            ->where('effective_vector_space_identity', self::LEGACY_SPACE_IDENTITY)
            ->where('effective_vector_space_evidence_id', $evidence->id)
            ->count());

        $repeated = $this->guard()->recordEffectiveSpaceMapping($firstCandidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::ReuseIndexedSpace, $repeated->status);
        $this->assertSame(self::LEGACY_SPACE_IDENTITY, $firstCandidate->fresh()->effective_vector_space_identity);
    }

    public function test_recording_mapping_never_overwrites_a_different_mapping(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload());
        $evidence = $this->recorder()->record($source);

        $candidate = $this->revision();
        $foreignIdentity = 'rag:config-9:revision-9:text-embedding-3-large:3072:cosine';
        $this->mapSpace($candidate, $foreignIdentity, $evidence);

        $result = $this->guard()->recordEffectiveSpaceMapping($candidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $result->status);
        $this->assertSame('indexed_space_mismatch', $result->reasonCode);
        $this->assertSame($foreignIdentity, $candidate->fresh()->effective_vector_space_identity);
    }

    public function test_recording_mapping_requires_trusted_evidence(): void
    {
        Http::preventStrayRequests();
        $candidate = $this->revision();

        $result = $this->guard()->recordEffectiveSpaceMapping($candidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $result->status);
        $this->assertSame('reference_missing', $result->reasonCode);
        $this->assertNull($candidate->fresh()->effective_vector_space_identity);
        $this->assertNull($candidate->fresh()->effective_vector_space_evidence_id);
        Http::assertNothingSent();
    }

    public function test_recording_mapping_requires_a_compatible_candidate(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), '');
        $this->recorder()->record($source);
        $this->indexedDocument(self::LEGACY_SPACE_IDENTITY);

        $condition = $this->revision();
        $result = $this->guard()->recordEffectiveSpaceMapping($condition);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $result->status);
        $this->assertSame('provider_failure', $result->reasonCode);
        $this->assertNull($condition->fresh()->effective_vector_space_identity);
    }

    public function test_evidence_without_indexing_metadata_cannot_authorize_reuse_or_persist_mapping(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), $this->embeddingPayload(), $this->embeddingPayload());
        $evidence = $this->recorder()->record($source);

        // Bukti tersegel hanya membuktikan perilaku embedder. Identitas ruang
        // yang direkonstruksi dari source_configuration_id/source_revision_id
        // tidak membuktikan adanya titik Qdrant di ruang tersebut, jadi tanpa
        // metadata indexing yang mengaitkannya pemakaian ulang wajib ditolak.
        $mapped = $this->revision();
        $this->mapSpace($mapped, self::LEGACY_SPACE_IDENTITY, $evidence);
        $decision = $this->guard()->decide($mapped, EmbeddingIndexState::Populated);

        $candidate = $this->revision();
        $recorded = $this->guard()->recordEffectiveSpaceMapping($candidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('indexed_space_unattested', $decision->reasonCode);
        $this->assertNull($decision->effectiveSpaceIdentity);
        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $recorded->status);
        $this->assertSame('indexed_space_unattested', $recorded->reasonCode);
        $this->assertNull($candidate->fresh()->effective_vector_space_identity);
        $this->assertNull($candidate->fresh()->effective_vector_space_evidence_id);
        Http::assertSentCount(1);
    }

    public function test_ready_indexing_metadata_attests_the_space_and_authorizes_reuse(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), $this->embeddingPayload(), $this->embeddingPayload());
        $evidence = $this->recorder()->record($source);

        // vector_space_identity baru diisi setelah upsert Qdrant berhasil, jadi
        // ia satu-satunya catatan aplikasi yang mengikat identitas ke vektor
        // yang benar-benar sudah ditulis.
        $this->indexedDocument(self::LEGACY_SPACE_IDENTITY);

        $mapped = $this->revision();
        $this->mapSpace($mapped, self::LEGACY_SPACE_IDENTITY, $evidence);
        $decision = $this->guard()->decide($mapped, EmbeddingIndexState::Populated);

        $candidate = $this->revision();
        $recorded = $this->guard()->recordEffectiveSpaceMapping($candidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::ReuseIndexedSpace, $decision->status);
        $this->assertSame('probe_match_verified_provenance', $decision->reasonCode);
        $this->assertSame(self::LEGACY_SPACE_IDENTITY, $decision->effectiveSpaceIdentity);
        $this->assertSame(EmbeddingSpaceDecisionStatus::ReuseIndexedSpace, $recorded->status);
        $this->assertSame('effective_space_mapping_verified', $recorded->reasonCode);
        $this->assertSame(self::LEGACY_SPACE_IDENTITY, $candidate->fresh()->effective_vector_space_identity);
        $this->assertSame(
            self::LEGACY_SPACE_IDENTITY,
            KnowledgeDocument::query()->where('vector_space_identity', self::LEGACY_SPACE_IDENTITY)->firstOrFail()->vector_space_identity,
        );
        $this->assertSame($evidence->id, (int) $candidate->fresh()->effective_vector_space_evidence_id);
        Http::assertSentCount(3);
    }

    public function test_indexing_metadata_attesting_another_space_does_not_authorize_reuse(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), $this->embeddingPayload(), $this->embeddingPayload());
        $evidence = $this->recorder()->record($source);
        $this->indexedDocument('rag:config-77:revision-77:text-embedding-3-large:3072:cosine');

        $mapped = $this->revision();
        $this->mapSpace($mapped, self::LEGACY_SPACE_IDENTITY, $evidence);
        $decision = $this->guard()->decide($mapped, EmbeddingIndexState::Populated);

        $candidate = $this->revision();
        $recorded = $this->guard()->recordEffectiveSpaceMapping($candidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $decision->status);
        $this->assertSame('indexed_space_unattested', $decision->reasonCode);
        $this->assertSame('indexed_space_unattested', $recorded->reasonCode);
        $this->assertNull($candidate->fresh()->effective_vector_space_identity);
        Http::assertSentCount(1);
    }

    public function test_unfinished_document_metadata_does_not_attest_a_space(): void
    {
        $source = $this->revision();
        $this->fakeEmbeddingResponses($this->embeddingPayload(), $this->embeddingPayload());
        $this->recorder()->record($source);
        $this->indexedDocument(self::LEGACY_SPACE_IDENTITY, ['status' => 'failed']);

        $candidate = $this->revision();
        $recorded = $this->guard()->recordEffectiveSpaceMapping($candidate);

        $this->assertSame(EmbeddingSpaceDecisionStatus::RejectReuse, $recorded->status);
        $this->assertSame('indexed_space_unattested', $recorded->reasonCode);
        $this->assertNull($candidate->fresh()->effective_vector_space_identity);
        Http::assertSentCount(1);
    }

    public function test_existing_space_identity_formula_remains_byte_identical(): void
    {
        $revision = $this->revision();

        $this->assertSame(self::LEGACY_SPACE_IDENTITY, (new EmbeddingRevisionResolver)->space($revision)->identity);
        $this->assertSame(
            self::LEGACY_SPACE_IDENTITY,
            EmbeddingRevisionResolver::identityFromComponents(
                (int) $revision->configuration_id,
                (int) $revision->id,
                EmbeddingRevisionResolver::MODEL,
                EmbeddingRevisionResolver::DIMENSION,
            ),
        );
    }

    private function revision(array $overrides = []): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Embedding guard test',
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

    private function mapSpace(
        AiProviderConfigurationRevision $revision,
        string $identity,
        AiEmbeddingReferenceEvidence $evidence,
    ): void {
        $revision->forceFill([
            'effective_vector_space_identity' => $identity,
            'effective_vector_space_evidence_id' => $evidence->id,
        ])->save();
    }

    /** @param array<string, mixed> $overrides */
    private function indexedDocument(string $vectorSpaceIdentity, array $overrides = []): KnowledgeDocument
    {
        return KnowledgeDocument::factory()->create(array_merge([
            'status' => 'ready',
            'vector_space_identity' => $vectorSpaceIdentity,
        ], $overrides));
    }

    private function guard(): AiEmbeddingActivationCompatibilityGuard
    {
        return new AiEmbeddingActivationCompatibilityGuard($this->evaluator());
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

    /**
     * Satu fake per test: body 200 berurutan, sedangkan string kosong berarti
     * kegagalan transport HTTP 500 untuk panggilan berikutnya.
     *
     * @param  list<string>  $payloads
     */
    private function fakeEmbeddingResponses(string ...$payloads): void
    {
        Http::fake(['provider.example.test/v1/embeddings' => function () use (&$payloads) {
            $payload = array_shift($payloads);

            return $payload === null || $payload === '' ? Http::response('', 500) : Http::response($payload);
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
