<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Services\AI\EmbeddingIndexState;
use App\Services\AI\KnowledgeIndexReadinessResult;
use App\Services\AI\KnowledgeIndexReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Kesiapan pengetahuan/indeks diuji di SQLite :memory: dengan Qdrant tiruan.
 *
 * Http::preventStrayRequests() memastikan tidak ada permintaan Qdrant/provider
 * nyata. Satu stub Http::fake per test: fake tambahan bersifat menumpuk (stub
 * lama menang), jadi kombinasi berbeda harus berada di test terpisah.
 */
class KnowledgeIndexReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private const COLLECTION_URL = 'http://127.0.0.1:6333/collections/prime_knowledge_qa';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['qdrant.url' => 'http://127.0.0.1:6333', 'qdrant.collection' => 'prime_knowledge_qa']);
    }

    public function test_empty_registry_and_exactly_empty_collection_remain_ambiguous_without_writer_barrier(): void
    {
        $this->fakeCollection(0);
        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state);
        $this->assertSame('indexing_activity_uncoordinated', $result->reasonCode);
        $this->assertSame(0, $result->documentCount);
        $this->assertSame(0, $result->unresolvedIndexingCount);
        $this->assertSame(0, $result->failedDocumentCount);
        $this->assertSame(0, $result->indexedPointCount);
        $this->assertTrue($result->collectionPresent);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0][0]->method());
        $this->assertSame('POST', $requests[1][0]->method());
        $this->assertSame(['exact' => true], $requests[1][0]->data());
    }

    public function test_missing_collection_and_empty_registry_remain_ambiguous(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response(['status' => 'error'], 404)]);
        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state);
        $this->assertSame('qdrant_collection_missing', $result->reasonCode);
        $this->assertFalse($result->collectionPresent);
        $this->assertNull($result->indexedPointCount);
        $this->assertCount(1, Http::recorded());
    }

    public function test_published_ready_document_with_points_is_populated(): void
    {
        $this->fakeCollection(12);
        $this->document(['status' => 'ready', 'is_published' => true, 'published_at' => now()]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Populated, $result->state, $result->reasonCode);
        $this->assertSame('indexed_points_present', $result->reasonCode);
        $this->assertSame(12, $result->indexedPointCount);
    }

    public function test_unpublished_ready_document_with_points_is_populated(): void
    {
        $this->fakeCollection(12);
        $this->document(['status' => 'ready', 'is_published' => false]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Populated, $result->state, $result->reasonCode);
        $this->assertSame('indexed_points_present', $result->reasonCode);
    }

    public function test_processing_document_without_points_is_ambiguous(): void
    {
        $this->fakeCollection(0);
        $this->document(['status' => 'processing', 'vector_space_identity' => null]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('indexing_activity_unresolved', $result->reasonCode);
        $this->assertSame(1, $result->unresolvedIndexingCount);
    }

    public function test_queued_uploaded_document_is_ambiguous(): void
    {
        $this->fakeCollection(0);
        $this->document(['status' => 'uploaded', 'vector_space_identity' => null]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('indexing_activity_unresolved', $result->reasonCode);
    }

    public function test_failed_document_with_points_is_populated(): void
    {
        // Gagal di tengah proses bisa meninggalkan titik; keberadaan titik adalah
        // bukti terkuat dan tidak boleh direduksi menjadi "kosong".
        $this->fakeCollection(5);
        $this->document(['status' => 'failed', 'vector_space_identity' => null, 'safe_error_code' => 'processing_failed']);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Populated, $result->state, $result->reasonCode);
        $this->assertSame('indexed_points_present', $result->reasonCode);
        $this->assertSame(5, $result->indexedPointCount);
    }

    public function test_failed_document_without_points_is_ambiguous(): void
    {
        $this->fakeCollection(0);
        $this->document(['status' => 'failed', 'vector_space_identity' => null, 'safe_error_code' => 'ingestion_failed']);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('unresolved_failed_documents', $result->reasonCode);
        $this->assertSame(1, $result->failedDocumentCount);
    }

    public function test_orphan_points_without_sql_documents_are_populated_without_space_filter(): void
    {
        // Titik lama (mis. FNC-01) ada di ruang vektor lain; filter ruang aktif
        // akan mengembalikan nol. Kesiapan wajib memakai jumlah total collection.
        $this->fakeCollection(76);
        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Populated, $result->state, $result->reasonCode);
        $this->assertSame('indexed_points_present', $result->reasonCode);
        $this->assertSame(76, $result->indexedPointCount);
        $this->assertSame(0, $result->documentCount);

        [, $countRequest] = Http::recorded()->all();
        $this->assertArrayNotHasKey('filter', $countRequest[0]->data());
    }

    public function test_sql_index_metadata_without_qdrant_points_is_ambiguous(): void
    {
        $this->fakeCollection(0);
        $this->document(['status' => 'ready']);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('index_points_missing', $result->reasonCode);
    }

    public function test_index_metadata_with_missing_collection_is_ambiguous(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response(['status' => 'error'], 404)]);
        $this->document(['status' => 'ready']);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('index_points_missing', $result->reasonCode);
        $this->assertFalse($result->collectionPresent);
        $this->assertNull($result->indexedPointCount);
    }

    public function test_unknown_document_status_is_ambiguous(): void
    {
        $this->fakeCollection(0);
        $this->document(['status' => 'archived', 'vector_space_identity' => null]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('unknown_document_status', $result->reasonCode);
    }

    public function test_ready_document_without_vector_space_identity_is_ambiguous(): void
    {
        $this->fakeCollection(3);
        $this->document(['status' => 'ready', 'vector_space_identity' => null]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('index_metadata_inconsistent', $result->reasonCode);
    }

    public function test_published_document_that_is_not_ready_is_ambiguous(): void
    {
        $this->fakeCollection(3);
        $this->document(['status' => 'failed', 'is_published' => true, 'vector_space_identity' => null]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('index_metadata_inconsistent', $result->reasonCode);
    }

    public function test_qdrant_http_failure_is_ambiguous_and_never_zero(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response('', 503)]);
        $this->document(['status' => 'ready']);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('qdrant_unavailable', $result->reasonCode);
        $this->assertNull($result->indexedPointCount);
        $this->assertNull($result->collectionPresent);
        $this->assertSame(1, $result->documentCount);
    }

    public function test_qdrant_transport_failure_is_ambiguous(): void
    {
        Http::fake([self::COLLECTION_URL => static function (): void {
            throw new ConnectionException('Connection failed.');
        }]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('qdrant_unavailable', $result->reasonCode);
    }

    public function test_authentication_rejection_is_distinguished_from_general_unavailability(): void
    {
        foreach ([401, 403] as $status) {
            Http::fake([self::COLLECTION_URL => Http::response(['status' => 'error'], $status)]);

            $result = $this->assess();

            $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
            $this->assertSame('qdrant_access_denied', $result->reasonCode);
            $this->assertNull($result->indexedPointCount);
            $this->assertNull($result->collectionPresent);
        }
    }

    public function test_server_side_rejection_is_not_misreported_as_access_denied(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response(['status' => 'error'], 500)]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('qdrant_unavailable', $result->reasonCode);
    }

    public function test_malformed_count_response_is_ambiguous(): void
    {
        Http::fake([
            self::COLLECTION_URL => Http::response($this->collectionBody()),
            self::COLLECTION_URL.'/points/count' => Http::response(['status' => 'ok', 'result' => []]),
        ]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('qdrant_response_invalid', $result->reasonCode);
        $this->assertNull($result->indexedPointCount);
    }

    public function test_invalid_exact_count_is_reported_as_invalid_response(): void
    {
        // Angka eksak negatif tidak pernah sah; hasil pengamatan tidak boleh
        // menyandikan nilai itu sebagai jumlah titik.
        Http::fake([
            self::COLLECTION_URL => Http::response($this->collectionBody()),
            self::COLLECTION_URL.'/points/count' => Http::response(['status' => 'ok', 'result' => ['count' => -1]]),
        ]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('qdrant_response_invalid', $result->reasonCode);
        $this->assertNull($result->indexedPointCount);
    }

    public function test_unsupported_multi_vector_collection_is_ambiguous(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response([
            'status' => 'ok',
            'result' => ['config' => ['params' => ['vectors' => ['dense' => ['size' => 3072, 'distance' => 'Cosine']]]]],
        ])]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('qdrant_collection_unsupported', $result->reasonCode);
        // Collection yang skemanya tidak dipahami tidak boleh dihitung sama sekali.
        $this->assertCount(1, Http::recorded());
    }

    public function test_registry_change_during_observation_is_ambiguous(): void
    {
        // Dokumen masuk di antara pembacaan registry pertama dan kedua: keadaan
        // tidak stabil, jadi klaim kosong tidak boleh diterima.
        Http::fake([
            self::COLLECTION_URL => Http::response($this->collectionBody()),
            self::COLLECTION_URL.'/points/count' => function () {
                $this->document(['status' => 'uploaded', 'vector_space_identity' => null]);

                return Http::response($this->countBody(0));
            },
        ]);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('observation_unstable', $result->reasonCode);
        $this->assertSame(1, $result->documentCount);
    }

    public function test_registry_read_failure_fails_closed_without_contacting_qdrant(): void
    {
        $this->failRegistryReadOnceAfter(allowedReads: 0);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('sql_unavailable', $result->reasonCode);
        $this->assertNull($result->documentCount);
        $this->assertNull($result->indexedPointCount);
        $this->assertNull($result->collectionPresent);
        Http::assertNothingSent();
    }

    public function test_registry_recheck_failure_keeps_collected_evidence_and_fails_closed(): void
    {
        $this->fakeCollection(0);
        $this->failRegistryReadOnceAfter(allowedReads: 1);

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Ambiguous, $result->state, $result->reasonCode);
        $this->assertSame('sql_unavailable', $result->reasonCode);
        $this->assertSame(0, $result->documentCount);
        // Count eksak sudah berhasil dibaca sebelum recheck gagal: nol yang
        // benar-benar terukur tidak boleh hilang menjadi null.
        $this->assertSame(0, $result->indexedPointCount);
        $this->assertTrue($result->collectionPresent);
        $this->assertCount(2, Http::recorded());
    }

    public function test_assessment_does_not_mutate_documents_or_provider_mappings(): void
    {
        $this->fakeCollection(9);
        $this->document(['status' => 'ready', 'is_published' => true, 'published_at' => now()]);
        $configuration = AiProviderConfiguration::query()->create(['display_name' => 'Read only', 'purpose' => 'embedding']);
        $revision = new AiProviderConfigurationRevision;
        $revision->forceFill([
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
        ])->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        $documentsBefore = $this->tableRows('knowledge_documents');
        $configurationsBefore = $this->tableRows('ai_provider_configurations');
        $revisionsBefore = $this->tableRows('ai_provider_configuration_revisions');

        $result = $this->assess();

        $this->assertSame(EmbeddingIndexState::Populated, $result->state, $result->reasonCode);
        $this->assertSame($documentsBefore, $this->tableRows('knowledge_documents'));
        $this->assertSame($configurationsBefore, $this->tableRows('ai_provider_configurations'));
        $this->assertSame($revisionsBefore, $this->tableRows('ai_provider_configuration_revisions'));
        $this->assertNull(DB::table('ai_provider_configuration_revisions')->where('id', $revision->id)->value('effective_vector_space_identity'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET'
            && ! Str::endsWith($request->url(), '/points/count'));
    }

    private function assess(): KnowledgeIndexReadinessResult
    {
        return app(KnowledgeIndexReadinessService::class)->assess();
    }

    /** @param array<string, mixed> $overrides */
    private function document(array $overrides = []): KnowledgeDocument
    {
        return KnowledgeDocument::factory()->create($overrides);
    }

    private function fakeCollection(int $count): void
    {
        Http::fake([
            self::COLLECTION_URL => Http::response($this->collectionBody()),
            self::COLLECTION_URL.'/points/count' => Http::response($this->countBody($count)),
        ]);
    }

    private function failRegistryReadOnceAfter(int $allowedReads): void
    {
        $reads = 0;
        $armed = true;

        // Gagalkan tepat satu pembacaan registry berikutnya pada level koneksi
        // tanpa menyentuh skema, supaya kegagalan bisa diarahkan ke pembacaan
        // awal atau ke recheck dan tidak aktif lagi setelah melempar.
        DB::connection()->beforeExecuting(function () use (&$reads, &$armed, $allowedReads): void {
            if (! $armed || ++$reads <= $allowedReads) {
                return;
            }

            $armed = false;
            throw new RuntimeException('Pembacaan registry digagalkan oleh test.');
        });
    }

    /** @return list<array<string, mixed>> */
    private function tableRows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return array<string, mixed> */
    private function collectionBody(): array
    {
        return ['status' => 'ok', 'result' => ['config' => ['params' => ['vectors' => ['size' => 3072, 'distance' => 'Cosine']]]]];
    }

    /** @return array<string, mixed> */
    private function countBody(int $count): array
    {
        return ['status' => 'ok', 'result' => ['count' => $count]];
    }
}
