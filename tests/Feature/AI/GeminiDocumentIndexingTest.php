<?php

namespace Tests\Feature\AI;

use App\Jobs\IndexKnowledgeDocumentInGemini;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\GeminiDocumentIndexingService;
use App\Services\AI\GeminiDocumentIndexState;
use App\Services\AI\GeminiIndexingContinuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Integrasi registry Knowledge Document dengan indeksasi Gemini File Search:
 * penjadwalan, identitas dokumen, status operasi, dan pengamanan retry.
 *
 * Tahap pekerjaan dijalankan lewat GeminiDocumentIndexingService::process()
 * (kode yang sama dengan job antrean), sehingga tidak ada panggilan jaringan
 * sungguhan dan hasilnya tidak bergantung pada driver antrean.
 */
class GeminiDocumentIndexingTest extends TestCase
{
    use RefreshDatabase;

    private const CREDENTIAL = 'fictional-prime-gemini-key';

    private const ENV_FALLBACK_CREDENTIAL = 'fictional-env-fallback-key';

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta';

    private const STORE = 'fileSearchStores/store-prime-dev';

    private const IMPORT_URL = self::BASE_URL.'/fileSearchStores/store-prime-dev:importFile';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
    }

    public function test_indexing_is_opt_in_and_creates_no_state_while_disabled(): void
    {
        $document = $this->document();
        Http::preventStrayRequests();

        app(GeminiDocumentIndexingService::class)->schedule($document);

        $this->assertDatabaseCount('knowledge_document_gemini_indexings', 0);
        Http::assertNothingSent();
    }

    public function test_sync_queue_configuration_is_rejected_without_remote_calls(): void
    {
        config([
            'gemini_file_search.indexing.enabled' => true,
            'gemini_file_search.indexing.store' => 'store-prime-dev',
            'queue.default' => 'sync',
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Http::preventStrayRequests();
        $document = $this->document();

        app(GeminiDocumentIndexingService::class)->schedule($document);

        $index = $this->indexOf($document);
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('queue_sync_unsafe', $index->safe_error_code);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_missing_store_configuration_is_recorded_as_failed(): void
    {
        config([
            'gemini_file_search.indexing.enabled' => true,
            'gemini_file_search.indexing.store' => '',
            'queue.default' => 'database',
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Http::preventStrayRequests();
        $document = $this->document();

        app(GeminiDocumentIndexingService::class)->schedule($document);

        $index = $this->indexOf($document);
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('configuration_invalid', $index->safe_error_code);
        $this->assertNull($index->store_id);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_schedule_persists_document_identity_and_dispatches_one_job(): void
    {
        $this->configureIndexing();
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Http::preventStrayRequests();
        $document = $this->document(['version' => '1.10']);

        $this->schedule($document);

        $index = $this->indexOf($document);
        $this->assertSame(GeminiDocumentIndexState::Queued, $index->state);
        $this->assertSame(1, $index->generation);
        $this->assertSame($document->document_code, $index->document_code);
        $this->assertSame('1.10', $index->document_version);
        $this->assertSame($document->checksum, $index->document_checksum);
        $this->assertSame(self::STORE, $index->store_id);
        $this->assertNull($index->google_file_id);
        $this->assertNull($index->operation_name);
        $this->assertNull($index->safe_error_code);
        Queue::assertPushed(
            IndexKnowledgeDocumentInGemini::class,
            fn (IndexKnowledgeDocumentInGemini $job): bool => $job->indexId === $index->id && $job->generation === 1,
        );
        Queue::assertNotPushed(ProcessKnowledgeDocument::class);
        Http::assertNothingSent();
    }

    public function test_indexing_is_not_queued_when_the_surrounding_transaction_rolls_back(): void
    {
        $this->configureIndexing();
        config(['queue.default' => 'database']);
        Http::preventStrayRequests();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);

        try {
            DB::transaction(function () use ($service, $document): void {
                $service->schedule($document);

                throw new RuntimeException('rollback sintetis');
            });
        } catch (RuntimeException) {
            // Transaksi di-roll back; yang diperiksa adalah jejaknya.
        }

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('knowledge_document_gemini_indexings', 0);
        Http::assertNothingSent();
    }

    public function test_upload_and_import_use_active_credential_and_store_identity(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        config(['ai.providers.gemini.key' => self::ENV_FALLBACK_CREDENTIAL]);
        $document = $this->document(['version' => '2.0']);
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-1']]),
            self::IMPORT_URL => Http::response([
                'name' => self::STORE.'/operations/import-1',
                'done' => false,
            ]),
        ]);

        $continuation = $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiIndexingContinuation::AwaitRemoteCompletion, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Pending, $index->state);
        $this->assertSame('files/prime-file-1', $index->google_file_id);
        $this->assertSame(self::STORE.'/operations/import-1', $index->operation_name);
        $this->assertNull($index->google_document_name);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::UPLOAD_URL.'/files'
            && $request->header('x-goog-api-key') === [self::CREDENTIAL]);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::IMPORT_URL
            && $request->header('x-goog-api-key') === [self::CREDENTIAL]
            && $request['fileName'] === 'files/prime-file-1'
            && $request['customMetadata'] === [
                ['key' => 'document_code', 'stringValue' => $document->document_code],
                ['key' => 'version', 'stringValue' => '2.0'],
            ]);
        Http::assertSentCount(2);
    }

    public function test_completed_import_records_verified_identity_without_publishing(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-2']]),
            self::IMPORT_URL => Http::response([
                'name' => self::STORE.'/operations/import-2',
                'done' => true,
                'response' => ['documentName' => self::STORE.'/documents/doc-2'],
            ]),
        ]);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Completed, $index->state);
        $this->assertSame(self::STORE.'/documents/doc-2', $index->google_document_name);

        $document->refresh();
        $this->assertFalse($document->is_published);
        $this->assertNull($document->published_at);
        $this->assertSame('ready', $document->status);
        Http::assertSentCount(2);
    }

    public function test_remote_pending_operation_completes_only_after_verified_status_check(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-3']]),
            self::IMPORT_URL => Http::response(['name' => self::STORE.'/operations/import-3', 'done' => false]),
        ]);
        $service->process($index->id, $index->generation);

        Http::fake([
            self::operationUrl('import-3') => Http::response([
                'name' => self::STORE.'/operations/import-3',
                'done' => true,
                'response' => ['documentName' => self::STORE.'/documents/doc-3'],
            ]),
        ]);

        $continuation = $service->process($index->id, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Completed, $index->fresh()->state);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::operationUrl('import-3'));
    }

    public function test_remote_failed_operation_marks_index_failed(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-4']]),
            self::IMPORT_URL => Http::response(['name' => self::STORE.'/operations/import-4', 'done' => false]),
        ]);
        $service->process($index->id, $index->generation);

        Http::fake([
            self::operationUrl('import-4') => Http::response([
                'name' => self::STORE.'/operations/import-4',
                'done' => true,
                'error' => ['message' => 'detail provider rahasia'],
            ]),
        ]);

        $service->process($index->id, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('remote_failed', $index->safe_error_code);
        $this->assertStringNotContainsString('rahasia', (string) $index->safe_error_message);
        $this->assertNull($index->google_document_name);
    }

    public function test_malformed_import_response_is_unknown_and_not_retried(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-5']]),
            self::IMPORT_URL => Http::response(['name' => ['invalid'], 'fictional-secret' => 'detail internal']),
        ]);

        $service->process($index->id, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->state);
        $this->assertSame('import_outcome_unknown', $index->safe_error_code);
        $this->assertStringNotContainsString('detail internal', (string) $index->safe_error_message);

        $service->process($index->id, $index->generation);

        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->fresh()->state);
        Http::assertSentCount(2);
    }

    public function test_document_identity_from_another_store_is_never_completed(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-6']]),
            self::IMPORT_URL => Http::response([
                'name' => self::STORE.'/operations/import-6',
                'done' => true,
                'response' => ['documentName' => 'fileSearchStores/other-store/documents/doc-6'],
            ]),
        ]);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->state);
        $this->assertSame('import_outcome_unknown', $index->safe_error_code);
        $this->assertNull($index->google_document_name);
        $this->assertFalse($document->refresh()->is_published);
    }

    public function test_upload_transport_failure_marks_uncertain_outcome_and_is_not_repeated(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([self::UPLOAD_URL.'/files' => Http::failedConnection()]);

        $service->process($index->id, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->state);
        $this->assertSame('upload_outcome_unknown', $index->safe_error_code);
        $this->assertNull($index->google_file_id);
        Http::assertSentCount(1);

        // Fake baru agar yang diperiksa hanya hasil pemanggilan schedule kedua.
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        $service->schedule($document);
        Queue::assertNothingPushed();
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->fresh()->state);
        Http::assertSentCount(1);
    }

    public function test_pending_operation_resumes_by_status_check_only(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        // Kondisi nyata setelah impor diterima: baris menunggu hanya boleh
        // diperiksa lewat GET status, tanpa unggah maupun impor ulang.
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => 'pending',
            'google_file_id' => 'files/prime-file-7',
            'operation_name' => self::STORE.'/operations/import-7',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            self::operationUrl('import-7') => Http::response([
                'name' => self::STORE.'/operations/import-7',
                'done' => false,
            ]),
        ]);

        $continuation = $service->process($index->id, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::AwaitRemoteCompletion, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Pending, $index->fresh()->state);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::operationUrl('import-7'));
    }

    public function test_failed_import_restarts_with_the_known_file_identity(): void
    {
        $this->configureIndexing();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => 'failed',
            'google_file_id' => 'files/prime-file-8',
            'safe_error_code' => 'import_rejected',
        ]);

        $this->activateGeminiRevision();
        Http::preventStrayRequests();
        Http::fake([self::IMPORT_URL => Http::response([
            'name' => self::STORE.'/operations/import-8',
            'done' => false,
        ])]);

        $this->schedule($document);

        // Job hasil restart hanya ter-dispatch; tahap impor dijalankan langsung
        // dengan generation baru agar benar-benar diterima process().
        $index->refresh();
        app(GeminiDocumentIndexingService::class)->process($index->id, $index->generation);
        $index->refresh();
        Queue::assertPushed(
            IndexKnowledgeDocumentInGemini::class,
            fn (IndexKnowledgeDocumentInGemini $job): bool => $job->uniqueId() === $index->id.':2',
        );
        $this->assertSame(2, $index->generation);
        $this->assertSame('files/prime-file-8', $index->google_file_id);
        $this->assertSame(self::STORE.'/operations/import-8', $index->operation_name);
        $this->assertSame(GeminiDocumentIndexState::Pending, $index->state);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::UPLOAD_URL.'/files');
    }

    public function test_job_from_previous_generation_is_ignored(): void
    {
        $this->configureIndexing();
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Http::preventStrayRequests();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);

        $continuation = $service->process($index->id, $index->generation + 1);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Queued, $index->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_changed_document_version_marks_index_failed_without_remote_calls(): void
    {
        $this->configureIndexing();
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Http::preventStrayRequests();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        KnowledgeDocument::query()->whereKey($document->id)->update(['version' => '3.0']);

        $service->process($index->id, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('document_identity_changed', $index->safe_error_code);
        Http::assertNothingSent();
    }

    public function test_stale_upload_claim_becomes_unknown_instead_of_reuploading(): void
    {
        $this->configureIndexing();
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Http::preventStrayRequests();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => 'uploading',
            'updated_at' => now()->subMinutes(11),
        ]);

        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        $service->schedule($document);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->state);
        $this->assertSame('stale_attempt', $index->safe_error_code);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_document_upload_still_dispatches_legacy_processing_only_when_gemini_is_disabled(): void
    {
        Queue::fake([ProcessKnowledgeDocument::class, IndexKnowledgeDocumentInGemini::class]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);

        $this->actingAs($admin)->post(route('knowledge-documents.store'), [
            'title' => 'FNC-99 SOP',
            'version' => '1.0',
            'document' => UploadedFile::fake()->createWithContent('fnc-99.pdf', "%PDF-1.4\nSynthetic\n"),
        ])->assertRedirect(route('knowledge-documents.index'));

        Queue::assertPushed(ProcessKnowledgeDocument::class);
        Queue::assertNotPushed(IndexKnowledgeDocumentInGemini::class);
        $this->assertDatabaseCount('knowledge_document_gemini_indexings', 0);
    }

    private function configureIndexing(string $store = 'store-prime-dev'): void
    {
        config([
            'gemini_file_search.indexing.enabled' => true,
            'gemini_file_search.indexing.store' => $store,
            // Worker asinkron adalah prasyarat; tanpa ini schedule menolak jalan.
            'queue.default' => 'database',
        ]);
    }

    private function document(array $overrides = []): KnowledgeDocument
    {
        Storage::fake('local');
        $document = KnowledgeDocument::factory()->create($overrides);
        Storage::disk('local')->put($document->storage_path, "%PDF-1.4\nSynthetic PRIME SOP\n");

        return $document;
    }

    private function indexOf(KnowledgeDocument $document): KnowledgeDocumentGeminiIndex
    {
        return KnowledgeDocumentGeminiIndex::query()
            ->where('knowledge_document_id', $document->id)
            ->sole();
    }

    private function operationUrl(string $operation): string
    {
        return self::BASE_URL.'/'.self::STORE.'/operations/'.$operation;
    }

    private function schedule(KnowledgeDocument $document): KnowledgeDocumentGeminiIndex
    {
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        app(GeminiDocumentIndexingService::class)->schedule($document);

        $index = $this->indexOf($document);
        Queue::assertPushed(
            IndexKnowledgeDocumentInGemini::class,
            fn (IndexKnowledgeDocumentInGemini $job): bool => $job->indexId === $index->id && $job->generation === $index->generation,
        );

        return $index;
    }

    private function process(KnowledgeDocumentGeminiIndex $index, int $generation): GeminiIndexingContinuation
    {
        return app(GeminiDocumentIndexingService::class)->process($index->id, $generation);
    }

    private function activateGeminiRevision(): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Fictional Gemini',
            'purpose' => 'chat',
        ]);
        $revision = $configuration->revisions()->make([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.8-flash',
            'credential' => self::CREDENTIAL,
            'enabled' => true,
        ])->forceFill([
            'revision_number' => 1,
            'test_state' => 'passed',
            'test_attempt_id' => 'fictional-attempt-id',
            'test_started_at' => now(),
            'tested_at' => now(),
        ]);
        $revision->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $revision;
    }
}
