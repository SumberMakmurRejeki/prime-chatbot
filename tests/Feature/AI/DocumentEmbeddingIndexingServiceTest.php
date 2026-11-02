<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\DocumentEmbeddingIndexingService;
use App\Services\AI\DocumentIngestion\DocumentChunk;
use App\Services\AI\EmbeddingRevisionResolver;
use App\Services\AI\SecureEmbeddingClient;
use App\Services\AI\VectorStore\DistanceMetric;
use App\Services\AI\VectorStore\RetrievalQuery;
use App\Services\AI\VectorStore\VectorChunk;
use App\Services\AI\VectorStore\VectorSpace;
use App\Services\AI\VectorStore\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentEmbeddingIndexingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_eligible_active_embedding_revision_can_index(): void
    {
        $store = new RecordingVectorStore;
        $service = $this->service($store);
        Http::preventStrayRequests();

        try {
            $service->index([$this->chunk()]);
            $this->fail('Missing active embedding revision must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('No eligible active embedding revision is available.', $exception->getMessage());
        }
        Http::assertNothingSent();

        $revision = $this->activateRevision(['test_state' => 'failed']);
        try {
            $service->index([$this->chunk()]);
            $this->fail('Failed test state must not be used.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('No eligible active embedding revision is available.', $exception->getMessage());
        }
        $this->assertSame([], $store->upserts);
        $this->assertNotNull($revision);
    }

    public function test_disabled_unattempted_and_wrong_dimension_revisions_are_not_eligible(): void
    {
        Http::preventStrayRequests();
        foreach ([
            ['enabled' => false],
            ['test_attempt_id' => null],
            ['tested_embedding_dimensions' => 1536],
        ] as $overrides) {
            $this->activateRevision($overrides);

            try {
                $this->service(new RecordingVectorStore)->index([$this->chunk()]);
                $this->fail('An ineligible active revision must fail closed.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('No eligible active embedding revision is available.', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_document_exceeding_atomic_vector_store_limit_fails_before_provider_io(): void
    {
        $this->activateRevision();
        config(['qdrant.max_upsert_points' => 32]);
        Http::preventStrayRequests();
        $store = new RecordingVectorStore;

        try {
            $this->service($store)->index(array_map(fn (int $index): DocumentChunk => $this->chunk($index), range(0, 32)));
            $this->fail('Oversized documents must be rejected before provider requests.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Document exceeds the configured atomic vector upsert limit.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame([], $store->upserts);
    }

    public function test_active_revision_batches_and_maps_complete_provenance_with_deterministic_ids(): void
    {
        $this->activateRevision();
        $store = new RecordingVectorStore;
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $vectors = array_fill(0, count($request['input']), array_fill(0, 3072, 0.125));

            return Http::response(['data' => array_map(static fn (array $vector, int $index): array => ['index' => $index, 'embedding' => $vector], $vectors, array_keys($vectors))]);
        });

        $chunks = array_map(fn (int $index): DocumentChunk => $this->chunk($index), range(0, 32));
        $service = $this->service($store);
        $space = $service->index($chunks);

        $this->assertSame(2, $calls);
        $this->assertSame('rag:config-1:revision-1:text-embedding-3-large:3072:cosine', $space->identity);
        $this->assertCount(1, $store->upserts);
        $this->assertCount(33, $store->upserts[0]['chunks']);
        $first = $store->upserts[0]['chunks'][0];
        $this->assertSame('DOC-1', $store->upserts[0]['documentId']);
        $this->assertSame('v1', $store->upserts[0]['version']);
        $this->assertSame('DOC-1', $first->metadata['document_code']);
        $this->assertSame(hash('sha256', 'source'), $first->metadata['source_sha256']);
        $this->assertSame('M-1', $first->metadata['machine_code']);
        $this->assertSame(1, $first->metadata['page_start']);
        $this->assertSame(2, $first->metadata['page_end']);
        $this->assertSame('8.2', $first->metadata['section_identifier']);
        $this->assertSame(1, $first->metadata['embedding_configuration_id']);
        $this->assertSame(1, $first->metadata['embedding_revision_id']);
        $this->assertSame('Section', $first->metadata['section_title']);
        $this->assertSame('stable-0', $first->metadata['stable_chunk_identity']);
        $this->assertSame('pipeline', $first->metadata['pipeline_fingerprint']);

        $renamed = $this->chunk(0, 'renamed.pdf');
        $this->assertSame($first->pointId, DocumentEmbeddingIndexingService::pointId($space, $renamed->stableChunkIdentity));
        $otherSpace = new VectorSpace('rag:config-1:revision-2:text-embedding-3-large:3072:cosine', 3072, DistanceMetric::Cosine);
        $this->assertNotSame($first->pointId, DocumentEmbeddingIndexingService::pointId($otherSpace, $chunks[0]->stableChunkIdentity));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->pointId);
        $this->assertSame('f2e95123-d1d1-5491-bfa4-5fd91d2dce30', $first->pointId);

        $service->index($chunks);
        $this->assertSame($first->pointId, $store->upserts[1]['chunks'][0]->pointId);
    }

    public function test_provider_response_count_mismatch_fails_closed_without_vector_write(): void
    {
        $this->activateRevision();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.125)]]])]);
        $store = new RecordingVectorStore;

        try {
            $this->service($store)->index([$this->chunk(0), $this->chunk(1)]);
            $this->fail('A short embedding response must fail closed.');
        } catch (\RuntimeException $exception) {
            // SDK memvalidasi jumlah embedding lebih dulu; pesan aman generik yang dijanjikan.
            $this->assertSame('Embedding operation failed.', $exception->getMessage());
            $this->assertStringNotContainsString('provider.example.test', $exception->getMessage());
            $this->assertStringNotContainsString('never-output-this', $exception->getMessage());
        }

        $this->assertSame([], $store->upserts);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://provider.example.test/v1/embeddings' && count($request['input']) === 2);
    }

    public function test_wrong_vector_dimension_fails_closed_without_vector_write(): void
    {
        $this->activateRevision();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3071, 0.125)]]])]);
        $store = new RecordingVectorStore;

        try {
            $this->service($store)->index([$this->chunk(0)]);
            $this->fail('A wrong vector dimension must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Embedding provider returned an invalid response.', $exception->getMessage());
        }

        $this->assertSame([], $store->upserts);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://provider.example.test/v1/embeddings');
    }

    public function test_non_finite_provider_vector_fails_closed_before_vector_write(): void
    {
        $this->activateRevision();
        Http::preventStrayRequests();
        $vector = '['.implode(',', array_fill(0, 3072, '1e999')).']';
        Http::fake(['provider.example.test/v1/embeddings' => Http::response('{"data":[{"index":0,"embedding":'.$vector.'}]}')]);
        $store = new RecordingVectorStore;

        try {
            $this->service($store)->index([$this->chunk(0)]);
            $this->fail('A non-finite embedding vector must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Embedding provider returned an invalid response.', $exception->getMessage());
        }

        $this->assertSame([], $store->upserts);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://provider.example.test/v1/embeddings');
    }

    public function test_reordered_provider_response_still_maps_vectors_to_the_correct_chunks(): void
    {
        $this->activateRevision();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response($this->providerPayload([
            ['index' => 1, 'value' => '0.25'],
            ['index' => 0, 'value' => '0.125'],
        ]))]);
        $store = new RecordingVectorStore;

        $this->service($store)->index([$this->chunk(0), $this->chunk(1)]);

        $chunks = $store->upserts[0]['chunks'];
        $this->assertCount(2, $chunks);
        $this->assertSame(0, $chunks[0]->chunkIndex);
        $this->assertCount(3072, $chunks[0]->vector);
        $this->assertSame(0.125, $chunks[0]->vector[0]);
        $this->assertSame(1, $chunks[1]->chunkIndex);
        $this->assertSame(0.25, $chunks[1]->vector[0]);

    }

    public function test_missing_duplicate_negative_or_overregistered_embedding_indices_are_rejected(): void
    {
        $this->activateRevision();
        Http::preventStrayRequests();
        $cases = [
            [$this->providerPayload([['value' => '0.125']]), [$this->chunk(0)]],
            [$this->providerPayload([['index' => -1, 'value' => '0.125']]), [$this->chunk(0)]],
            [$this->providerPayload([['index' => 0, 'value' => '0.125'], ['index' => 0, 'value' => '0.25']]), [$this->chunk(0), $this->chunk(1)]],
            [$this->providerPayload([['index' => 0, 'value' => '0.125'], ['index' => 2, 'value' => '0.25']]), [$this->chunk(0), $this->chunk(1)]],
            [$this->providerPayload([['index' => 0, 'value' => '0.125'], ['index' => 1, 'value' => '0.25']]), [$this->chunk(0)]],
        ];

        foreach ($cases as [$payload, $chunks]) {
            Http::fake(['provider.example.test/v1/embeddings' => Http::response($payload)]);
            $store = new RecordingVectorStore;

            try {
                $this->service($store)->index($chunks);
                $this->fail('Invalid embedding indices must fail closed.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Embedding operation failed.', $exception->getMessage());
            }

            $this->assertSame([], $store->upserts);
        }
    }

    public function test_inconsistent_document_scope_is_rejected_before_provider_io(): void
    {
        $this->activateRevision();
        Http::preventStrayRequests();
        $chunks = [$this->chunk(0), $this->chunk(1)];
        $chunks[1] = new DocumentChunk('another', 'OTHER', 'v1', 'source.pdf', hash('sha256', 'source'), 'M-1', 'pipeline', 1, 2, [8], '8.2', 'Section', 1, 1, 'content', [], 'stable-other');

        $this->expectException(InvalidArgumentException::class);
        $this->service(new RecordingVectorStore)->index($chunks);
    }

    public function test_non_list_or_empty_prepared_chunks_are_rejected_before_provider_io(): void
    {
        $this->activateRevision();
        Http::preventStrayRequests();
        $store = new RecordingVectorStore;
        $service = $this->service($store);

        foreach ([[], [0 => $this->chunk(0), 5 => $this->chunk(1)], ['bukan-chunk']] as $chunks) {
            try {
                $service->index($chunks);
                $this->fail('Prepared chunks must be a non-empty list of document chunks.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertSame([], $store->upserts);
        Http::assertNothingSent();
    }

    /** @param list<array{index?: int, value: string}> $entries */
    private function providerPayload(array $entries): string
    {
        $encoded = array_map(static function (array $entry): string {
            $embedding = implode(',', array_fill(0, 3072, $entry['value']));
            $index = array_key_exists('index', $entry) ? '"index":'.(string) $entry['index'].',' : '';

            return '{'.$index.'"embedding":['.$embedding.']}';
        }, $entries);

        return '{"data":['.implode(',', $encoded).']}';
    }

    private function activateRevision(array $overrides = []): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Embedding',
            'purpose' => 'embedding',
        ]);
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

    private function chunk(int $index = 0, string $filename = 'source.pdf'): DocumentChunk
    {
        return new DocumentChunk('doc-identity', 'DOC-1', 'v1', $filename, hash('sha256', 'source'), 'M-1', 'pipeline', 1, 2, [8, 2], '8.2', 'Section', 1, $index, 'Chunk text '.$index, [], 'stable-'.$index);
    }

    private function service(RecordingVectorStore $store): DocumentEmbeddingIndexingService
    {
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        return new DocumentEmbeddingIndexingService(
            new EmbeddingRevisionResolver,
            new SecureEmbeddingClient($this->app, $this->app->make(AiEndpointPinner::class)),
            $store,
        );
    }
}

final class RecordingVectorStore implements VectorStore
{
    /** @var list<array{space: VectorSpace, documentId: string, version: string, chunks: list<VectorChunk>, active: bool}> */
    public array $upserts = [];

    public function isReady(): bool
    {
        return true;
    }

    public function ensureCollection(VectorSpace $space): void {}

    public function upsert(VectorSpace $space, string $documentId, string $documentVersion, array $chunks, bool $retrievalActive = true): void
    {
        $this->upserts[] = ['space' => $space, 'documentId' => $documentId, 'version' => $documentVersion, 'chunks' => $chunks, 'active' => $retrievalActive];
    }

    public function search(VectorSpace $space, RetrievalQuery $query): array
    {
        return [];
    }

    public function deleteVersion(string $documentId, string $documentVersion): void {}

    public function setRetrievalActive(VectorSpace $space, string $documentId, string $documentVersion, bool $active): void {}
}
