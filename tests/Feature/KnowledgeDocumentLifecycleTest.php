<?php

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\DocumentIngestion\PdfIngestionConfig;
use App\Services\AI\DocumentIngestion\PdfIngestionService;
use App\Services\AI\DocumentIngestion\PdfTextExtractor;
use App\Services\AI\DocumentIngestion\TextChunker;
use App\Services\AI\DocumentIngestion\TextNormalizer;
use App\Services\AI\KnowledgeDocumentService;
use App\Services\AI\VectorStore\RetrievalQuery;
use App\Services\AI\VectorStore\VectorChunk;
use App\Services\AI\VectorStore\VectorSpace;
use App\Services\AI\VectorStore\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeDocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_is_indexed_inactive_and_marked_ready_only_after_success(): void
    {
        Storage::fake('local');
        $revision = $this->activateEmbeddingRevision();
        $store = new KnowledgeDocumentTestVectorStore;
        $this->app->instance(VectorStore::class, $store);
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => "FNC-01 Procedure\nInspect the cooling system and confirm pressure.\n", 'stderr' => '', 'exit_code' => 0]));
        $this->fakeEmbeddingProvider();
        $document = $this->storedDocument();

        app(KnowledgeDocumentService::class)->process($document->id);
        $document->refresh();

        $this->assertSame('ready', $document->status);
        $this->assertFalse($document->is_published);
        $this->assertSame(1, $document->chunk_count);
        $this->assertSame(1, $document->page_count);
        $this->assertSame('rag:config-'.$revision->configuration_id.':revision-'.$revision->id.':text-embedding-3-large:3072:cosine', $document->vector_space_identity);
        $this->assertCount(1, $store->upserts);
        $this->assertFalse($store->upserts[0]['active']);
        $this->assertSame($document->checksum, $store->upserts[0]['chunks'][0]->metadata['source_sha256']);
        $this->assertSame('FNC-01-MANUAL', $store->upserts[0]['documentId']);
        $this->assertSame('1.0', $store->upserts[0]['version']);
    }

    public function test_extraction_failure_never_marks_ready_or_discloses_raw_diagnostics(): void
    {
        Storage::fake('local');
        $this->activateEmbeddingRevision();
        $store = new KnowledgeDocumentTestVectorStore;
        $this->app->instance(VectorStore::class, $store);
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => '', 'stderr' => 'private-credential-value', 'exit_code' => 1]));
        Http::preventStrayRequests();
        $document = $this->storedDocument();

        app(KnowledgeDocumentService::class)->process($document->id);
        $document->refresh();

        $this->assertSame('failed', $document->status);
        $this->assertSame('ingestion_failed', $document->safe_error_code);
        $this->assertStringNotContainsString('private-credential-value', $document->safe_error_message);
        $this->assertFalse($document->is_published);
        $this->assertSame([], $store->upserts);
        Http::assertNothingSent();
    }

    public function test_indexing_failure_keeps_document_failed_and_retry_reuses_the_same_registry_row(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->activateEmbeddingRevision();
        $store = new KnowledgeDocumentTestVectorStore;
        $store->upsertFailure = new \RuntimeException('secret provider credential must not be surfaced');
        $this->app->instance(VectorStore::class, $store);
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => "FNC-01 Procedure\nCheck alignment.\n", 'stderr' => '', 'exit_code' => 0]));
        $this->fakeEmbeddingProvider();
        $document = $this->storedDocument();

        app(KnowledgeDocumentService::class)->process($document->id);
        $failed = $document->fresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('processing_failed', $failed->safe_error_code);
        $this->assertStringNotContainsString('secret provider credential', $failed->safe_error_message);
        $this->assertFalse($failed->is_published);
        $this->assertSame(1, KnowledgeDocument::query()->where('document_code', $document->document_code)->where('version', $document->version)->count());

        $store->upsertFailure = null;
        app(KnowledgeDocumentService::class)->retry($document->id);
        $this->assertSame('uploaded', $document->fresh()->status);
        Queue::assertPushed(ProcessKnowledgeDocument::class, fn (ProcessKnowledgeDocument $job): bool => $job->documentId === $document->id);
        app(KnowledgeDocumentService::class)->process($document->id);
        $this->assertCount(2, $store->upserts);
        $this->assertSame(
            array_map(static fn (VectorChunk $chunk): int|string => $chunk->pointId, $store->upserts[0]['chunks']),
            array_map(static fn (VectorChunk $chunk): int|string => $chunk->pointId, $store->upserts[1]['chunks']),
        );
    }

    public function test_only_ready_document_is_published_and_vector_points_are_activated(): void
    {
        Storage::fake('local');
        $revision = $this->activateEmbeddingRevision();
        $store = new KnowledgeDocumentTestVectorStore;
        $this->app->instance(VectorStore::class, $store);
        $this->fakeEmbeddingProvider();
        $document = $this->storedDocument([
            'status' => 'ready',
            'vector_space_identity' => 'rag:config-'.$revision->configuration_id.':revision-'.$revision->id.':text-embedding-3-large:3072:cosine',
            'chunk_count' => 1,
            'page_count' => 1,
            'pipeline_fingerprint' => 'known-fingerprint',
            'processed_at' => now(),
        ]);
        $publisher = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);

        $published = app(KnowledgeDocumentService::class)->publish($document->id, $publisher->id);

        $this->assertTrue($published->is_published);
        $this->assertSame('ready', $published->status);
        $this->assertSame($publisher->id, $published->published_by);
        $this->assertNotNull($published->published_at);
        $this->assertSame([[
            'space' => 'rag:config-'.$revision->configuration_id.':revision-'.$revision->id.':text-embedding-3-large:3072:cosine',
            'documentId' => 'FNC-01-MANUAL',
            'version' => '1.0',
            'active' => true,
        ]], $store->activations);
    }

    public function test_uploaded_document_cannot_be_published_before_indexing(): void
    {
        Storage::fake('local');
        $this->activateEmbeddingRevision();
        $store = new KnowledgeDocumentTestVectorStore;
        $this->app->instance(VectorStore::class, $store);
        $document = $this->storedDocument();
        $publisher = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);

        try {
            app(KnowledgeDocumentService::class)->publish($document->id, $publisher->id);
            $this->fail('An uploaded document must not be published.');
        } catch (\DomainException $exception) {
            $this->assertSame('Hanya dokumen READY yang belum dipublikasikan dapat dipublikasikan.', $exception->getMessage());
        }

        $this->assertSame([], $store->activations);
        $this->assertFalse($document->fresh()->is_published);
    }

    private function storedDocument(array $overrides = []): KnowledgeDocument
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $content = "%PDF-1.4\nSynthetic FNC-01 technical procedure\n";
        $path = 'knowledge-documents/FNC-01-MANUAL/1.0/source.pdf';
        Storage::disk('local')->put($path, $content);

        return KnowledgeDocument::factory()->create(array_merge([
            'document_code' => 'FNC-01-MANUAL',
            'title' => 'Synthetic FNC-01 procedure',
            'original_filename' => 'fnc-01.pdf',
            'version' => '1.0',
            'checksum' => hash('sha256', $content),
            'status' => 'uploaded',
            'is_published' => false,
            'storage_path' => $path,
            'file_size' => strlen($content),
            'page_count' => null,
            'chunk_count' => null,
            'vector_space_identity' => null,
            'pipeline_fingerprint' => null,
            'processed_at' => null,
            'uploaded_by' => $user->id,
        ], $overrides));
    }

    /** @param array{stdout: string, stderr: string, exit_code: int} $outcome */
    private function ingestionService(array $outcome): PdfIngestionService
    {
        $config = PdfIngestionConfig::fromArray(config('ai.document_ingestion'));

        return new PdfIngestionService(
            new PdfTextExtractor($config, static fn (): array => $outcome),
            new TextNormalizer,
            new TextChunker,
            $config,
        );
    }

    private function activateEmbeddingRevision(): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create(['display_name' => 'Embedding', 'purpose' => 'embedding']);
        $revision = new AiProviderConfigurationRevision;
        $revision->forceFill([
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
        ])->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        return $revision;
    }

    private function fakeEmbeddingProvider(): void
    {
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 0, 'embedding' => array_fill(0, 3072, 0.125)],
        ]])]);
    }
}

final class KnowledgeDocumentTestVectorStore implements VectorStore
{
    /** @var list<array{space: VectorSpace, documentId: string, version: string, chunks: list<VectorChunk>, active: bool}> */
    public array $upserts = [];

    /** @var list<array{space: string, documentId: string, version: string, active: bool}> */
    public array $activations = [];

    public ?\Throwable $upsertFailure = null;

    public function isReady(): bool
    {
        return true;
    }

    public function ensureCollection(VectorSpace $space): void {}

    public function upsert(VectorSpace $space, string $documentId, string $documentVersion, array $chunks, bool $retrievalActive = true): void
    {
        $this->upserts[] = ['space' => $space, 'documentId' => $documentId, 'version' => $documentVersion, 'chunks' => $chunks, 'active' => $retrievalActive];
        if ($this->upsertFailure !== null) {
            throw $this->upsertFailure;
        }
    }

    public function setRetrievalActive(VectorSpace $space, string $documentId, string $documentVersion, bool $active): void
    {
        $this->activations[] = ['space' => $space->identity, 'documentId' => $documentId, 'version' => $documentVersion, 'active' => $active];
    }

    public function search(VectorSpace $space, RetrievalQuery $query): array
    {
        return [];
    }

    public function deleteVersion(string $documentId, string $documentVersion): void {}
}
