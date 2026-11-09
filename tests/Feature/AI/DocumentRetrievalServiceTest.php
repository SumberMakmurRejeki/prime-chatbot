<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\DocumentRetrievalService;
use App\Services\AI\KnowledgeDocumentService;
use App\Services\AI\RetrievalEvidenceSelector;
use App\Services\AI\SecureEmbeddingClient;
use App\Services\AI\VectorStore\DistanceMetric;
use App\Services\AI\VectorStore\RetrievalQuery;
use App\Services\AI\VectorStore\VectorSearchResult;
use App\Services\AI\VectorStore\VectorSpace;
use App\Services\AI\VectorStore\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class DocumentRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_is_embedded_in_the_active_revision_space_with_bounded_top_k_and_active_only_search(): void
    {
        $this->activateRevision();
        $store = new RecordingRetrievalStore;
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.125)],
        ]])]);

        $service = app(DocumentRetrievalService::class);
        $results = $service->search('How should an operator assess equipment condition?');

        $this->assertSame([], $results);
        $this->assertSame('rag:config-1:revision-1:text-embedding-3-large:3072:cosine', $store->space->identity);
        $this->assertSame(3072, $store->space->dimension);
        $this->assertSame(DistanceMetric::Cosine, $store->space->distance);
        $this->assertSame(20, $store->query->limit);
        $this->assertTrue($store->query->onlyActive);
        $this->assertSame(array_fill(0, 3072, 0.125), $store->query->vector);
        Http::assertSentCount(1);
    }

    public function test_missing_ineligible_revision_states_fail_closed_before_provider_or_vector_store_io(): void
    {
        Http::preventStrayRequests();
        $this->pinEndpoint();

        $states = [
            'no configuration' => null,
            'failed test state' => ['test_state' => 'failed'],
            'disabled revision' => ['enabled' => false],
            'unattempted revision' => ['test_attempt_id' => null],
            'wrong tested dimension' => ['tested_embedding_dimensions' => 1536],
            'unsupported model' => ['model_id' => 'text-embedding-3-small'],
            'unsupported driver' => ['driver' => 'gemini'],
        ];

        foreach ($states as $label => $overrides) {
            if ($overrides === null) {
                AiProviderConfiguration::query()->delete();
            } else {
                AiProviderConfiguration::query()->delete();
                $this->activateRevision($overrides);
            }

            $store = new RecordingRetrievalStore;
            $this->app->instance(VectorStore::class, $store);

            try {
                app(DocumentRetrievalService::class)->search('Pertanyaan');
                $this->fail("An ineligible revision must fail closed: {$label}");
            } catch (RuntimeException $exception) {
                $this->assertSame('No eligible active embedding revision is available.', $exception->getMessage());
            }

            $this->assertNull($store->query);
            $this->assertSame([], $store->calls);
        }

        Http::assertNothingSent();
    }

    public function test_active_revision_defines_the_vector_space_identity_used_for_retrieval(): void
    {
        $first = $this->activateRevision();
        $second = $first->replicate();
        $second->forceFill(['revision_number' => 2, 'test_attempt_id' => 'attempt-2']);
        $second->save();
        $first->configuration->forceFill(['active_revision_id' => $second->id])->save();
        $this->app->instance(VectorStore::class, $store = new RecordingRetrievalStore);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.5)],
        ]])]);

        app(DocumentRetrievalService::class)->search('Pertanyaan');

        $this->assertSame(
            sprintf('rag:config-%d:revision-%d:text-embedding-3-large:3072:cosine', $second->configuration_id, $second->id),
            $store->space->identity,
        );
        $this->assertNotSame(
            sprintf('rag:config-%d:revision-%d:text-embedding-3-large:3072:cosine', $first->configuration_id, $first->id),
            $store->space->identity,
        );
    }

    public function test_invalid_provider_embedding_responses_fail_closed_without_any_search(): void
    {
        $this->activateRevision();
        $store = new RecordingRetrievalStore;
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();

        $payloads = [
            'count mismatch' => ['data' => [
                ['index' => 0, 'embedding' => array_fill(0, 3072, 0.1)],
                ['index' => 1, 'embedding' => array_fill(0, 3072, 0.1)],
            ]],
            'duplicate index' => ['data' => [
                ['index' => 0, 'embedding' => array_fill(0, 3072, 0.1)],
            ]],
            'missing index' => ['data' => [
                ['embedding' => array_fill(0, 3072, 0.1)],
            ]],
            'wrong dimension' => ['data' => [
                ['index' => 0, 'embedding' => array_fill(0, 1536, 0.1)],
            ]],
            'non finite component' => ['raw' => '{"data":[{"index":0,"embedding":['.str_repeat('0.1,', 3071).'1e999]}]}'],
        ];

        foreach ($payloads as $label => $payload) {
            Http::fake(['provider.example.test/v1/embeddings' => Http::response($payload['raw'] ?? $payload)]);

            try {
                app(DocumentRetrievalService::class)->search('Pertanyaan');
                $this->fail("An invalid embedding response must fail closed: {$label}");
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            $this->assertSame([], $store->calls);
            $this->assertNull($store->query);
        }
    }

    public function test_blank_and_oversized_questions_are_rejected_before_any_io(): void
    {
        $this->activateRevision();
        $store = new RecordingRetrievalStore;
        $this->app->instance(VectorStore::class, $store);
        Http::preventStrayRequests();

        foreach (['', '   ', str_repeat('a', 16_385)] as $question) {
            try {
                app(DocumentRetrievalService::class)->search($question);
                $this->fail('An empty or oversized question must be rejected before provider or vector store IO.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
        $this->assertNull($store->query);
    }

    public function test_ranked_results_keep_store_order_and_truthful_citation_provenance(): void
    {
        $this->activateRevision();
        $this->publishedDocument();
        $store = new RecordingRetrievalStore;
        $store->results = [
            $this->hit('chunk-hi', 0.93, 2, 4, 4),
            $this->hit('chunk-mid', 0.81, 3, 3, 5),
            $this->hit('chunk-lo', 0.64, 4, 4, 7),
        ];
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)],
        ]])]);

        $results = app(DocumentRetrievalService::class)->search('Pertanyaan');

        $this->assertSame(['chunk-hi', 'chunk-mid', 'chunk-lo'], array_column($results, 'pointId'));
        $this->assertSame([0.93, 0.81, 0.64], array_column($results, 'score'));
        $this->assertSame('DOC-7', $results[0]->provenance['document_code']);
        $this->assertSame('2.0', $results[0]->provenance['document_version']);
        $this->assertSame('3.4', $results[0]->provenance['section_identifier']);
        $this->assertSame('Inspection criteria', $results[0]->provenance['section_title']);
        $this->assertSame(2, $results[0]->provenance['page_start']);
        $this->assertSame(4, $results[0]->provenance['page_end']);
        $this->assertSame(4, $results[0]->provenance['chunk_index']);
        $this->assertSame('stable-4', $results[0]->provenance['stable_chunk_identity']);
        $this->assertSame('Evidence text 4', $results[0]->text);
        $this->assertSame('rag:config-1:revision-1:text-embedding-3-large:3072:cosine', $results[0]->vectorSpace);
    }

    public function test_sibling_sections_below_raw_top_five_are_selected_within_final_limit(): void
    {
        $this->activateRevision();
        $this->publishedDocument();
        $store = new RecordingRetrievalStore;
        $store->results = [
            $this->sectionHit('top-a', 0.99, '1.1'),
            $this->sectionHit('top-b', 0.98, '2.1'),
            $this->sectionHit('top-c', 0.97, '3.1'),
            $this->sectionHit('family-1', 0.96, '7.1'),
            $this->sectionHit('top-e', 0.95, '4.1'),
            $this->sectionHit('family-2', 0.94, '7.2'),
            $this->sectionHit('family-3', 0.93, '7.3'),
        ];
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)],
        ]])]);

        $results = app(DocumentRetrievalService::class)->search('generic question');

        $this->assertCount(5, $results);
        $this->assertContains('family-1', array_column($results, 'pointId'));
        $this->assertContains('family-2', array_column($results, 'pointId'));
        $this->assertSame([0.99, 0.98, 0.96, 0.94, 0.93], array_column($results, 'score'));
    }

    public function test_family_selection_is_deterministic_and_preserves_candidate_scores(): void
    {
        $this->activateRevision();
        $this->publishedDocument();
        $store = new RecordingRetrievalStore;
        $store->results = [
            $this->sectionHit('other-1', 0.91, '1.1'),
            $this->sectionHit('other-2', 0.90, '2.1'),
            $this->sectionHit('other-3', 0.89, '3.1'),
            $this->sectionHit('other-4', 0.88, '4.1'),
            $this->sectionHit('other-5', 0.87, '5.1'),
            $this->sectionHit('family-a', 0.60, '9.1'),
            $this->sectionHit('family-b', 0.59, '9.2'),
        ];
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)],
        ]])]);

        $results = app(DocumentRetrievalService::class)->search('generic question');

        $this->assertSame(['other-1', 'other-2', 'other-3', 'other-4', 'other-5'], array_column($results, 'pointId'));
        $this->assertSame([0.91, 0.90, 0.89, 0.88, 0.87], array_column($results, 'score'));
    }

    private function sectionHit(string $pointId, float $score, ?string $section): VectorSearchResult
    {
        $result = $this->hit($pointId, $score, 1, 1, 1);
        $provenance = $result->provenance;
        $provenance['section_identifier'] = $section;
        $provenance['stable_chunk_identity'] = $pointId;

        return new VectorSearchResult($result->pointId, $result->score, $result->documentId, $result->documentVersion, $result->vectorSpace, $result->locator, $result->pageNumber, $result->text, $provenance);
    }

    public function test_null_or_unrelated_section_metadata_falls_back_to_similarity_order(): void
    {
        $selector = app(RetrievalEvidenceSelector::class);
        $candidates = [
            $this->sectionHit('null-a', 0.95, null),
            $this->sectionHit('other-a', 0.94, '2.1'),
            $this->sectionHit('other-b', 0.93, '8.1'),
            $this->sectionHit('null-b', 0.92, null),
            $this->sectionHit('other-c', 0.91, '14.1'),
            $this->sectionHit('null-c', 0.90, null),
        ];

        $results = $selector->select($candidates, 5);

        $this->assertSame(['null-a', 'other-a', 'other-b', 'null-b', 'other-c'], array_column($results, 'pointId'));
    }

    public function test_repeated_selection_is_deterministic_for_identical_candidates(): void
    {
        $selector = app(RetrievalEvidenceSelector::class);
        $candidates = [
            $this->sectionHit('single-a', 0.90, '4.1'),
            $this->sectionHit('family-a', 0.88, '6.1'),
            $this->sectionHit('family-b', 0.86, '6.2'),
            $this->sectionHit('single-b', 0.86, '5.1'),
            $this->sectionHit('family-c', 0.84, '6.3'),
        ];

        $first = $selector->select($candidates, 5);
        $second = $selector->select($candidates, 5);

        $this->assertSame(array_column($first, 'pointId'), array_column($second, 'pointId'));
        $this->assertSame(['single-a', 'family-a', 'family-b', 'single-b', 'family-c'], array_column($first, 'pointId'));
        $this->assertSame([0.90, 0.88, 0.86, 0.86, 0.84], array_column($first, 'score'));
    }

    public function test_candidate_pool_respects_configured_store_ceiling(): void
    {
        config(['qdrant.max_retrieval_limit' => 7]);
        $this->activateRevision();
        $store = new RecordingRetrievalStore;
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)],
        ]])]);

        app(DocumentRetrievalService::class)->search('generic question');

        $this->assertSame(7, $store->query->limit);
    }

    public function test_retrieval_never_performs_operational_vector_store_writes(): void
    {
        $this->activateRevision();
        $this->publishedDocument();
        $store = new RecordingRetrievalStore;
        $store->results = [$this->hit('chunk', 0.5, 1, 1, 0)];
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.1)],
        ]])]);

        app(DocumentRetrievalService::class)->search('Pertanyaan');

        $this->assertSame(['search'], $store->calls);
    }

    public function test_only_mysql_ready_published_document_versions_are_retrieval_eligible(): void
    {
        $this->activateRevision();
        KnowledgeDocument::factory()->create([
            'document_code' => 'DOC-7',
            'version' => '2.0',
            'status' => 'ready',
            'is_published' => false,
        ]);
        $store = new RecordingRetrievalStore;
        $store->results = [$this->hit('unpublished', 0.99, 1, 1, 0)];
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.1)],
        ]])]);

        $this->assertSame([], app(DocumentRetrievalService::class)->search('Pertanyaan'));
    }

    public function test_superseded_revision_is_excluded_from_retrieval_even_when_its_vector_point_is_active(): void
    {
        $this->activateRevision();
        $store = new RecordingRetrievalStore;
        $this->app->instance(VectorStore::class, $store);
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.1)],
        ]])]);
        $publisher = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $revisionTwo = KnowledgeDocument::factory()->create([
            'document_code' => 'DOC-7',
            'version' => 'Rev.02',
        ]);
        $revisionThree = KnowledgeDocument::factory()->create([
            'document_code' => 'DOC-7',
            'version' => 'Rev.03',
        ]);

        $documents = app(KnowledgeDocumentService::class);
        $documents->publish($revisionTwo->id, $publisher->id);
        $documents->publish($revisionThree->id, $publisher->id);

        // Titik Qdrant lama belum dinonaktifkan; MySQL menjadi gerbang penolak.
        $store->results = [
            $this->revisionHit('rev-02-point', 'Rev.02'),
            $this->revisionHit('rev-03-point', 'Rev.03'),
        ];

        $results = app(DocumentRetrievalService::class)->search('Pertanyaan');

        $this->assertSame(['rev-03-point'], array_map(static fn (VectorSearchResult $result): string => $result->pointId, $results));
    }

    public function test_embedding_provider_failure_retains_the_original_exception(): void
    {
        $revision = $this->activateRevision();
        $this->pinEndpoint();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['error' => ['message' => 'upstream unavailable']], 503)]);

        try {
            app(SecureEmbeddingClient::class)->embed($revision, ['Pertanyaan uji']);
            $this->fail('A failed embedding request must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Embedding operation failed.', $exception->getMessage());
            $this->assertInstanceOf(Throwable::class, $exception->getPrevious());
            $this->assertNotSame($exception, $exception->getPrevious());
        }

        Http::assertSentCount(1);
    }

    private function publishedDocument(): void
    {
        KnowledgeDocument::factory()->create([
            'document_code' => 'DOC-7',
            'version' => '2.0',
            'status' => 'ready',
            'is_published' => true,
        ]);
    }

    private function revisionHit(string $pointId, string $version): VectorSearchResult
    {
        return new VectorSearchResult(
            $pointId,
            0.9,
            'DOC-7',
            $version,
            'rag:config-1:revision-1:text-embedding-3-large:3072:cosine',
            'p1-1',
            1,
            'Evidence text for '.$version,
            [
                'document_code' => 'DOC-7',
                'document_version' => $version,
                'section_identifier' => '3.4',
                'section_title' => 'Inspection criteria',
                'page_start' => 1,
                'page_end' => 1,
                'chunk_index' => 0,
                'stable_chunk_identity' => 'stable-'.$version,
            ],
        );
    }

    private function hit(string $pointId, float $score, int $pageStart, int $pageEnd, int $chunkIndex): VectorSearchResult
    {
        return new VectorSearchResult(
            $pointId,
            $score,
            'DOC-7',
            '2.0',
            'rag:config-1:revision-1:text-embedding-3-large:3072:cosine',
            sprintf('p%d-%d', $pageStart, $pageEnd),
            $pageStart,
            'Evidence text '.$chunkIndex,
            [
                'document_code' => 'DOC-7',
                'document_version' => '2.0',
                'section_identifier' => '3.4',
                'section_title' => 'Inspection criteria',
                'page_start' => $pageStart,
                'page_end' => $pageEnd,
                'chunk_index' => $chunkIndex,
                'stable_chunk_identity' => 'stable-'.$chunkIndex,
            ],
        );
    }

    private function pinEndpoint(): void
    {
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
    }

    private function activateRevision(array $overrides = []): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create(['display_name' => 'Embedding', 'purpose' => 'embedding']);
        $revision = new AiProviderConfigurationRevision;
        $revision->forceFill(array_merge([
            'configuration_id' => $configuration->id,
            'revision_number' => 1,
            'driver' => 'openai-compatible',
            'model_id' => 'text-embedding-3-large',
            'base_url' => 'https://provider.example.test/v1',
            'credential' => 'never-output-this',
            'test_state' => 'passed',
            'test_attempt_id' => 'attempt-1',
            'tested_at' => now(),
            'tested_embedding_dimensions' => 3072,
        ], $overrides))->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $revision;
    }
}

final class RecordingRetrievalStore implements VectorStore
{
    public ?VectorSpace $space = null;

    public ?RetrievalQuery $query = null;

    /** @var list<VectorSearchResult> */
    public array $results = [];

    /** @var list<string> */
    public array $calls = [];

    public function isReady(): bool
    {
        return true;
    }

    public function ensureCollection(VectorSpace $space): void
    {
        $this->calls[] = 'ensureCollection';
    }

    public function upsert(VectorSpace $space, string $documentId, string $documentVersion, array $chunks, bool $retrievalActive = true): void
    {
        $this->calls[] = 'upsert';
    }

    public function search(VectorSpace $space, RetrievalQuery $query): array
    {
        $this->calls[] = 'search';
        $this->space = $space;
        $this->query = $query;

        return $this->results;
    }

    public function deleteVersion(string $documentId, string $documentVersion): void
    {
        $this->calls[] = 'deleteVersion';
    }

    public function setRetrievalActive(VectorSpace $space, string $documentId, string $documentVersion, bool $active): void
    {
        $this->calls[] = 'setRetrievalActive';
    }
}
