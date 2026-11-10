<?php

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use App\Models\User;
use App\Services\AI\DocumentIngestion\PdfIngestionConfig;
use App\Services\AI\DocumentIngestion\PdfIngestionService;
use App\Services\AI\DocumentIngestion\PdfTextExtractor;
use App\Services\AI\DocumentIngestion\TextChunker;
use App\Services\AI\DocumentIngestion\TextNormalizer;
use App\Services\AI\GeminiDocumentIndexState;
use App\Services\AI\KnowledgeDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lifecycle registry Knowledge Base Admin: proses PDF → READY → publikasi.
 *
 * Kesiapan proses registry dan kesiapan indeks Gemini sengaja dipisahkan:
 * proses PDF tidak lagi memakai embedding/Qdrant, sedangkan publikasi hanya
 * boleh terjadi setelah bukti indeks Gemini File Search terverifikasi.
 * Seluruh keputusan publikasi hanya membaca keadaan persisten (MySQL dan
 * konfigurasi Laravel); Http::preventStrayRequests() menjaga kontrak
 * tanpa-jaringan tersebut.
 */
class KnowledgeDocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 'fileSearchStores/store-prime-dev';

    /** Pesan gerbang publikasi saat bukti indeks Gemini tidak terverifikasi. */
    private const UNVERIFIED_INDEX_MESSAGE = 'Publikasi ditolak: indeks Gemini File Search dokumen ini belum terverifikasi.';

    private ?AiProviderConfigurationRevision $geminiRevision = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['gemini_file_search.indexing.store' => 'store-prime-dev']);
    }

    public function test_document_is_processed_and_marked_ready_without_embedding_provider_or_qdrant(): void
    {
        Storage::fake('local');
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => "FNC-01 Procedure\nInspect the cooling system and confirm pressure.\n", 'stderr' => '', 'exit_code' => 0]));
        $document = $this->storedDocument();

        app(KnowledgeDocumentService::class)->process($document->id);
        $document->refresh();

        $this->assertSame('ready', $document->status);
        $this->assertFalse($document->is_published);
        $this->assertSame(1, $document->chunk_count);
        $this->assertSame(1, $document->page_count);
        $this->assertNotNull($document->processed_at);
        $this->assertSame(PdfIngestionConfig::fromArray(config('ai.document_ingestion'))->fingerprint(), $document->pipeline_fingerprint);
        // Jalur Gemini tidak menulis provenance ruang vektor baru; metadata
        // Qdrant historis tetap apa adanya dan tidak dihapus.
        $this->assertNull($document->vector_space_identity);
        Http::assertNothingSent();
    }

    public function test_extraction_failure_never_marks_ready_or_discloses_raw_diagnostics(): void
    {
        Storage::fake('local');
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => '', 'stderr' => 'private-credential-value', 'exit_code' => 1]));
        $document = $this->storedDocument();

        app(KnowledgeDocumentService::class)->process($document->id);
        $document->refresh();

        $this->assertSame('failed', $document->status);
        $this->assertSame('ingestion_failed', $document->safe_error_code);
        $this->assertStringNotContainsString('private-credential-value', (string) $document->safe_error_message);
        $this->assertFalse($document->is_published);
        $this->assertNull($document->processed_at);
        Http::assertNothingSent();
    }

    public function test_checksum_mismatch_aborts_processing_without_marking_ready(): void
    {
        Storage::fake('local');
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => "FNC-01 Procedure\nInspect the cooling system.\n", 'stderr' => '', 'exit_code' => 0]));
        $document = $this->storedDocument(['checksum' => hash('sha256', 'not-the-stored-file')]);

        app(KnowledgeDocumentService::class)->process($document->id);
        $document->refresh();

        $this->assertSame('failed', $document->status);
        $this->assertSame('processing_failed', $document->safe_error_code);
        $this->assertNull($document->processed_at);
        $this->assertFalse($document->is_published);
    }

    public function test_pdf_processing_failure_preserves_current_published_revision_history(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $publisher = $this->adminPublisher();
        $current = $this->publishedRevision('SOP-PROCESS-FAIL-001', $publisher);
        $publishedAt = $current->published_at->toISOString();
        $candidate = $this->storedDocument([
            'document_code' => 'SOP-PROCESS-FAIL-001',
            'version' => 'Rev.03',
            'checksum' => hash('sha256', 'processing-failure-candidate'),
        ]);
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => '', 'stderr' => 'invalid PDF text', 'exit_code' => 1]));

        app(KnowledgeDocumentService::class)->process($candidate->id);

        $this->assertSame('failed', $candidate->fresh()->status);
        $this->assertFalse($candidate->fresh()->is_published);
        $this->assertNull($candidate->fresh()->published_at);
        $this->assertTrue($current->fresh()->is_published);
        $this->assertSame($publishedAt, $current->fresh()->published_at->toISOString());
        $this->assertSame($publisher->id, $current->fresh()->published_by);
        $this->assertSame(1, KnowledgeDocument::query()
            ->where('document_code', 'SOP-PROCESS-FAIL-001')
            ->where('is_published', true)
            ->count());
        Http::assertNothingSent();
    }

    public function test_processing_failure_keeps_document_failed_and_retry_reuses_the_same_registry_row(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => '', 'stderr' => 'broken', 'exit_code' => 1]));
        $document = $this->storedDocument();

        app(KnowledgeDocumentService::class)->process($document->id);
        $failed = $document->fresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('ingestion_failed', $failed->safe_error_code);
        $this->assertFalse($failed->is_published);

        // Retry tidak membuat versi baru: satu baris registry dipakai ulang.
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => "FNC-01 Procedure\nCheck alignment.\n", 'stderr' => '', 'exit_code' => 0]));
        app(KnowledgeDocumentService::class)->retry($document->id);
        $this->assertSame('uploaded', $document->fresh()->status);
        Queue::assertPushed(ProcessKnowledgeDocument::class, fn (ProcessKnowledgeDocument $job): bool => $job->documentId === $document->id);

        app(KnowledgeDocumentService::class)->process($document->id);

        $this->assertSame('ready', $document->fresh()->status);
        $this->assertSame(1, KnowledgeDocument::query()
            ->where('document_code', $document->document_code)
            ->where('version', $document->version)
            ->count());
        Http::assertNothingSent();
    }

    public function test_processed_and_indexed_document_never_publishes_itself(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $this->app->instance(PdfIngestionService::class, $this->ingestionService(['stdout' => "FNC-01 Procedure\nInspect the cooling system.\n", 'stderr' => '', 'exit_code' => 0]));
        $document = $this->storedDocument();

        app(KnowledgeDocumentService::class)->process($document->id);
        $document->refresh();
        $this->assertFalse($document->is_published);

        $this->completedIndex($document);

        // Indeksasi selesai pun bukan otorisasi publikasi; hanya aksi Admin.
        $this->assertFalse($document->refresh()->is_published);
        $this->assertNull($document->published_at);
        $this->assertNull($document->published_by);
        Http::assertNothingSent();
    }

    public function test_completed_index_with_matching_provenance_permits_admin_publication(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $document = $this->storedDocument(['status' => 'ready']);
        $index = $this->completedIndex($document);
        $publisher = $this->adminPublisher();

        $published = app(KnowledgeDocumentService::class)->publish($document->id, $publisher->id);

        $this->assertTrue($published->is_published);
        $this->assertSame('ready', $published->status);
        $this->assertSame($publisher->id, $published->published_by);
        $this->assertNotNull($published->published_at);
        // Publikasi hanya membaca bukti indeks; baris indeksasi tidak berubah,
        // bukan dibuat ulang dan bukan dimundurkan.
        $this->assertSame(GeminiDocumentIndexState::Completed, $index->fresh()->state);
        $this->assertSame($index->import_identity, $index->fresh()->import_identity);
        $this->assertSame(1, KnowledgeDocumentGeminiIndex::query()->count());
        Http::assertNothingSent();
    }

    public function test_unfinished_or_uncertain_indexing_blocks_publication_and_preserves_current_revision(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $publisher = $this->adminPublisher();
        $service = app(KnowledgeDocumentService::class);

        foreach ([GeminiDocumentIndexState::Pending, GeminiDocumentIndexState::Failed, GeminiDocumentIndexState::Unknown] as $state) {
            $current = $this->storedDocument([
                'document_code' => 'SOP-INDEX-'.$state->value,
                'version' => 'Rev.02',
                'checksum' => hash('sha256', 'current-'.$state->value),
                'status' => 'ready',
            ]);
            $this->completedIndex($current);
            $service->publish($current->id, $publisher->id);
            $publishedAt = $current->fresh()->published_at->toISOString();

            $candidate = $this->storedDocument([
                'document_code' => 'SOP-INDEX-'.$state->value,
                'version' => 'Rev.03',
                'checksum' => hash('sha256', 'candidate-'.$state->value),
                'status' => 'ready',
            ]);
            $this->completedIndex($candidate, ['state' => $state->value]);

            $this->assertPublicationRejected($candidate->id, $publisher->id);

            // Pengganti gagal tidak boleh menyentuh revisi terbit saat ini.
            $this->assertTrue($current->fresh()->is_published);
            $this->assertSame($publishedAt, $current->fresh()->published_at->toISOString());
            $this->assertSame($publisher->id, $current->fresh()->published_by);
            $this->assertFalse($candidate->fresh()->is_published);
            $this->assertNull($candidate->fresh()->published_at);
        }

        Http::assertNothingSent();
    }

    public function test_credential_revision_mismatch_blocks_publication_and_preserves_current_revision(): void
    {
        Storage::fake('local');
        $original = $this->activateGeminiRevision();
        $publisher = $this->adminPublisher();
        $current = $this->publishedRevision('SOP-ROTATE-001', $publisher);
        $publishedAt = $current->published_at->toISOString();

        // Rotasi kredensial: revisi yang mengesahkan impor tidak lagi aktif.
        $this->rotateGeminiRevision($original, 'fictional-rotated-gemini-key');
        Http::preventStrayRequests();

        $candidate = $this->storedDocument([
            'document_code' => 'SOP-ROTATE-001',
            'version' => 'Rev.03',
            'checksum' => hash('sha256', 'rotate-candidate'),
            'status' => 'ready',
        ]);
        $this->completedIndex($candidate);

        $this->assertPublicationRejected($candidate->id, $publisher->id);

        $this->assertTrue($current->fresh()->is_published);
        $this->assertSame($publishedAt, $current->fresh()->published_at->toISOString());
        $this->assertSame($publisher->id, $current->fresh()->published_by);
        $this->assertFalse($candidate->fresh()->is_published);
        $this->assertNull($candidate->fresh()->published_at);
        Http::assertNothingSent();
    }

    public function test_store_identity_mismatch_blocks_publication_and_preserves_current_revision(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $publisher = $this->adminPublisher();
        $current = $this->publishedRevision('SOP-STORE-001', $publisher);
        $publishedAt = $current->published_at->toISOString();

        $candidate = $this->storedDocument([
            'document_code' => 'SOP-STORE-001',
            'version' => 'Rev.03',
            'checksum' => hash('sha256', 'store-candidate'),
            'status' => 'ready',
        ]);
        $this->completedIndex($candidate, [
            'store_id' => 'fileSearchStores/store-old',
            'google_document_name' => 'fileSearchStores/store-old/documents/store-old-doc',
        ]);

        $this->assertPublicationRejected($candidate->id, $publisher->id);

        $this->assertTrue($current->fresh()->is_published);
        $this->assertSame($publishedAt, $current->fresh()->published_at->toISOString());
        $this->assertFalse($candidate->fresh()->is_published);
        $this->assertNull($candidate->fresh()->published_at);
        Http::assertNothingSent();
    }

    public function test_publication_route_shows_safe_indonesian_error_for_unverified_index(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $document = $this->storedDocument(['status' => 'ready']);
        $this->completedIndex($document, ['state' => 'unknown']);

        $this->actingAs($this->adminPublisher())
            ->post(route('knowledge-documents.publish', ['knowledgeDocument' => $document]))
            ->assertRedirect(route('knowledge-documents.index'))
            ->assertSessionHas('flash_error', self::UNVERIFIED_INDEX_MESSAGE);

        // Penolakan terjadi sebelum mutasi apa pun: dokumen tetap draf, tanpa
        // timestamp atau aktor publikasi.
        $this->assertFalse($document->fresh()->is_published);
        $this->assertNull($document->fresh()->published_at);
        $this->assertNull($document->fresh()->published_by);
        Http::assertNothingSent();
    }

    public function test_snapshot_and_import_identity_mismatch_block_publication_without_superseding(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $publisher = $this->adminPublisher();
        $current = $this->publishedRevision('SOP-STALE-001', $publisher);

        // Checksum registry berubah setelah indeks dibuat: snapshot indeks
        // tidak lagi menggambarkan dokumen yang sama.
        $replaced = $this->storedDocument([
            'document_code' => 'SOP-STALE-001',
            'version' => 'Rev.03',
            'checksum' => hash('sha256', 'original-candidate-content'),
            'status' => 'ready',
        ]);
        $this->completedIndex($replaced);
        KnowledgeDocument::query()->whereKey($replaced->id)->update(['checksum' => hash('sha256', 'replaced-content')]);

        // Baris lama tanpa token import identity tidak pernah dipercaya.
        $legacy = $this->storedDocument([
            'document_code' => 'SOP-LEGACY-001',
            'version' => 'Rev.01',
            'checksum' => hash('sha256', 'legacy-content'),
            'status' => 'ready',
        ]);
        $this->completedIndex($legacy, ['import_identity' => null]);

        $this->assertPublicationRejected($replaced->id, $publisher->id);
        $this->assertPublicationRejected($legacy->id, $publisher->id);

        $this->assertTrue($current->fresh()->is_published);
        $this->assertFalse($replaced->fresh()->is_published);
        $this->assertFalse($legacy->fresh()->is_published);
        Http::assertNothingSent();
    }

    public function test_publishing_new_revision_supersedes_previous_revision_exactly_once(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $publisher = $this->adminPublisher();
        $service = app(KnowledgeDocumentService::class);

        $revisionTwo = $this->storedDocument(['document_code' => 'SOP-UPCAST-001', 'version' => 'Rev.02', 'checksum' => hash('sha256', 'rev-02'), 'status' => 'ready']);
        $this->completedIndex($revisionTwo);
        $revisionThree = $this->storedDocument(['document_code' => 'SOP-UPCAST-001', 'version' => 'Rev.03', 'checksum' => hash('sha256', 'rev-03'), 'status' => 'ready']);
        $this->completedIndex($revisionThree);
        $otherSop = $this->storedDocument(['document_code' => 'SOP-OTHER-001', 'version' => 'Rev.01', 'checksum' => hash('sha256', 'other-sop'), 'status' => 'ready']);
        $this->completedIndex($otherSop);

        $service->publish($revisionTwo->id, $publisher->id);
        $service->publish($otherSop->id, $publisher->id);

        $publishedRevision = $service->publish($revisionThree->id, $publisher->id);

        $this->assertTrue($publishedRevision->is_published);
        // Revisi lama kehilangan flag current tetapi tetap arsip utuh.
        $this->assertFalse($revisionTwo->fresh()->is_published);
        $this->assertSame($publisher->id, $revisionTwo->fresh()->published_by);
        $this->assertNotNull($revisionTwo->fresh()->published_at);
        $this->assertSame('Rev.02', $revisionTwo->fresh()->version);
        $this->assertSame(1, KnowledgeDocument::query()
            ->where('document_code', 'SOP-UPCAST-001')
            ->where('is_published', true)
            ->count());
        $this->assertTrue($otherSop->fresh()->is_published);
        Http::assertNothingSent();
    }

    public function test_republishing_the_current_revision_is_rejected_and_keeps_original_provenance(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $publisher = $this->adminPublisher();
        $current = $this->publishedRevision('SOP-IDEMPOTENT-001', $publisher);
        $publishedAt = $current->published_at->toISOString();

        try {
            app(KnowledgeDocumentService::class)->publish($current->id, $publisher->id);
            $this->fail('Revisi yang sudah terbit tidak boleh dipublikasikan ulang.');
        } catch (\DomainException $exception) {
            $this->assertSame('Hanya dokumen READY yang belum dipublikasikan dapat dipublikasikan.', $exception->getMessage());
        }

        $this->assertTrue($current->fresh()->is_published);
        $this->assertSame($publishedAt, $current->fresh()->published_at->toISOString());
        $this->assertSame($publisher->id, $current->fresh()->published_by);
        Http::assertNothingSent();
    }

    public function test_non_ready_registry_state_cannot_be_published_even_with_verified_index(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $document = $this->storedDocument(['status' => 'uploaded']);
        $this->completedIndex($document);

        try {
            app(KnowledgeDocumentService::class)->publish($document->id, $this->adminPublisher()->id);
            $this->fail('Dokumen belum READY tidak boleh dipublikasikan.');
        } catch (\DomainException $exception) {
            $this->assertSame('Hanya dokumen READY yang belum dipublikasikan dapat dipublikasikan.', $exception->getMessage());
        }

        $this->assertFalse($document->fresh()->is_published);
        Http::assertNothingSent();
    }

    public function test_publication_reads_target_then_locks_same_code_scope_before_mutating(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $publisher = $this->adminPublisher();
        $current = $this->storedDocument(['document_code' => 'SOP-LOCK-001', 'version' => 'Rev.02', 'checksum' => hash('sha256', 'lock-rev-02'), 'status' => 'ready']);
        $this->completedIndex($current);
        app(KnowledgeDocumentService::class)->publish($current->id, $publisher->id);

        $candidate = $this->storedDocument(['document_code' => 'SOP-LOCK-001', 'version' => 'Rev.03', 'checksum' => hash('sha256', 'lock-rev-03'), 'status' => 'ready']);
        $this->completedIndex($candidate);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "knowledge_documents"') || str_starts_with($query->sql, 'update "knowledge_documents"')) {
                $queries[] = $query->sql;
            }
        });

        app(KnowledgeDocumentService::class)->publish($candidate->id, $publisher->id);

        $targetLookup = null;
        $scopeLock = null;
        $firstMutation = null;
        foreach ($queries as $index => $sql) {
            if (str_starts_with($sql, 'select') && str_contains($sql, 'where "knowledge_documents"."id" = ?')) {
                $targetLookup ??= $index;
            }
            if (str_starts_with($sql, 'select') && str_contains($sql, 'where "document_code" = ?')) {
                $scopeLock ??= $index;
            }
            if (str_starts_with($sql, 'update')) {
                $firstMutation ??= $index;
            }
        }

        // Kontrak kunci berkode sama: target dibaca tanpa kunci untuk menentukan
        // cakupan, lalu seluruh revisi berkode sama dibaca terkunci dengan urutan
        // id yang stabil (bebas deadlock), dan mutasi hanya setelah kunci.
        // SQLite mengompilasi lockForUpdate() tanpa klausa, jadi urutan baca dan
        // batas mutasi yang diverifikasi di sini; invarian satu-revisi-terbit
        // ditegaskan pada test supersede.
        $this->assertIsInt($targetLookup);
        $this->assertIsInt($scopeLock);
        $this->assertIsInt($firstMutation);
        $this->assertLessThan($scopeLock, $targetLookup);
        $this->assertLessThan($firstMutation, $scopeLock);
        $this->assertSame(1, KnowledgeDocument::query()
            ->where('document_code', 'SOP-LOCK-001')
            ->where('is_published', true)
            ->count());
    }

    public function test_admin_route_publishes_a_verified_revision_and_reports_success(): void
    {
        Storage::fake('local');
        $this->activateGeminiRevision();
        $document = $this->storedDocument(['status' => 'ready']);
        $this->completedIndex($document);
        $publisher = $this->adminPublisher();

        $this->actingAs($publisher)
            ->post(route('knowledge-documents.publish', ['knowledgeDocument' => $document]))
            ->assertRedirect(route('knowledge-documents.index'))
            ->assertSessionHas('flash_success', 'Dokumen siap dan telah dipublikasikan.');

        $this->assertTrue($document->fresh()->is_published);
        $this->assertSame($publisher->id, $document->fresh()->published_by);
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

    private function adminPublisher(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
    }

    /**
     * Revisi siap ini dipublikasikan lewat gerbang supaya bukti indeks dan
     * flag current saling konsisten sebelum kandidat pengganti diuji.
     */
    private function publishedRevision(string $code, User $publisher, string $version = 'Rev.02'): KnowledgeDocument
    {
        $current = $this->storedDocument([
            'document_code' => $code,
            'version' => $version,
            'checksum' => hash('sha256', $code.'-'.$version),
            'status' => 'ready',
        ]);
        $this->completedIndex($current);
        app(KnowledgeDocumentService::class)->publish($current->id, $publisher->id);

        return $current->refresh();
    }

    private function assertPublicationRejected(int $candidateId, int $publisherId): void
    {
        try {
            app(KnowledgeDocumentService::class)->publish($candidateId, $publisherId);
            $this->fail('Publikasi seharusnya ditolak untuk kandidat ini.');
        } catch (\DomainException $exception) {
            $this->assertSame(self::UNVERIFIED_INDEX_MESSAGE, $exception->getMessage());
        }
    }

    private function activateGeminiRevision(): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create(['display_name' => 'Gemini Chat', 'purpose' => 'chat']);
        $revision = $configuration->revisions()->make([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.8-flash',
            'credential' => 'never-output-this',
            'enabled' => true,
        ])->forceFill([
            'revision_number' => 1,
            'test_state' => 'passed',
            'test_attempt_id' => 'attempt-1',
            'test_started_at' => now(),
            'tested_at' => now(),
        ]);
        $revision->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $this->geminiRevision = $revision;
    }

    /**
     * Rotasi kredensial di slot konfigurasi yang sama: revisi baru aktif,
     * revisi lama tetap tersimpan sebagai bukti provenance impor.
     */
    private function rotateGeminiRevision(AiProviderConfigurationRevision $previous, string $credential): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->findOrFail($previous->configuration_id);
        $revision = $configuration->revisions()->make([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.8-flash',
            'credential' => $credential,
            'enabled' => true,
        ])->forceFill([
            'revision_number' => 2,
            'test_state' => 'passed',
            'test_attempt_id' => 'rotated-attempt-id',
            'test_started_at' => now(),
            'tested_at' => now(),
        ]);
        $revision->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $revision;
    }

    /**
     * Baris indeks completed dengan provenance yang sah sesuai konteks uji:
     * store terkonfigurasi dan revisi kredensial Gemini aktif saat ini.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function completedIndex(KnowledgeDocument $document, array $overrides = []): KnowledgeDocumentGeminiIndex
    {
        return KnowledgeDocumentGeminiIndex::create(array_merge([
            'knowledge_document_id' => $document->id,
            'document_code' => $document->document_code,
            'document_version' => $document->version,
            'document_checksum' => $document->checksum,
            'store_id' => self::STORE,
            'google_file_id' => 'files/synthetic-'.Str::lower(Str::random(8)),
            'google_document_name' => self::STORE.'/documents/synthetic-'.Str::lower(Str::random(8)),
            'state' => 'completed',
            'generation' => 1,
            'credential_configuration_id' => $this->geminiRevision?->configuration_id,
            'credential_revision_id' => $this->geminiRevision?->id,
            'import_identity' => 'g'.bin2hex(random_bytes(24)),
        ], $overrides));
    }
}
