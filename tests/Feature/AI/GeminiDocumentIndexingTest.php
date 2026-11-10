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
use Illuminate\Queue\MaxAttemptsExceededException;
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

    private const STORE_URL = self::BASE_URL.'/fileSearchStores/store-prime-dev';

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
        // Baris yang gagal karena konfigurasi belum pernah menulis: bukti tulis
        // harus tetap eksplisit agar pemulihan setelah perbaikan konfigurasi aman.
        $this->assertSame('not_started', $index->remote_write_evidence->value);
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
        $this->assertSame('not_started', $index->remote_write_evidence->value);
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
            self::STORE_URL => Http::response(['name' => self::STORE]),
            // Callback membaca baris sebelum provider menjawab: bukti tulis dan
            // state klaim harus sudah tersimpan saat permintaan keluar.
            self::UPLOAD_URL.'/files' => function () use ($index) {
                $claimed = $index->fresh();
                $this->assertSame(GeminiDocumentIndexState::Uploading, $claimed->state);
                $this->assertSame('may_have_started', $claimed->remote_write_evidence->value);

                return Http::response(['file' => ['name' => 'files/prime-file-1']]);
            },
            self::IMPORT_URL => function () use ($index) {
                $claimed = $index->fresh();
                $this->assertSame(GeminiDocumentIndexState::Importing, $claimed->state);
                $this->assertSame('may_have_started', $claimed->remote_write_evidence->value);

                return Http::response([
                    'name' => self::STORE.'/operations/import-1',
                    'done' => false,
                ]);
            },
        ]);

        $continuation = $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiIndexingContinuation::AwaitRemoteCompletion, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Pending, $index->state);
        $this->assertSame('may_have_started', $index->remote_write_evidence->value);
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
                ['key' => 'import_identity', 'stringValue' => $index->import_identity],
            ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::STORE_URL
            && $request->header('x-goog-api-key') === [self::CREDENTIAL]);
        Http::assertSentCount(4);
    }

    public function test_completed_import_records_verified_identity_without_publishing(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
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
        Http::assertSentCount(4);
    }

    public function test_completed_import_with_relative_identity_persists_canonical_document_name(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-16']]),
            // Bentuk respons yang dilaporkan integrasi nyata: identifier dokumen
            // tanpa prefix store, bukan resource name lengkap.
            self::IMPORT_URL => Http::response([
                'name' => self::STORE.'/operations/import-16',
                'done' => true,
                'response' => ['documentName' => 'prime-file-16-relative'],
            ]),
        ]);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Completed, $index->state);
        // Identitas yang disimpan tetap store-bound, bukan identifier relatif.
        $this->assertSame(self::STORE.'/documents/prime-file-16-relative', $index->google_document_name);
        $this->assertSame(1, $index->credential_configuration_id);
        $this->assertSame(1, $index->credential_revision_id);

        // Kesiapan indeks teknis bukan otorisasi publikasi SOP.
        $this->assertFalse($document->refresh()->is_published);
        $this->assertNull($document->published_at);
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
            self::STORE_URL => Http::response(['name' => self::STORE]),
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

    public function test_pending_operation_completes_with_relative_identity_as_canonical_name(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => 'pending',
            'google_file_id' => 'files/prime-file-17',
            'operation_name' => self::STORE.'/operations/import-17',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            $this->operationUrl('import-17') => Http::response([
                'name' => self::STORE.'/operations/import-17',
                'done' => true,
                'response' => ['documentName' => 'prime-file-17-relative'],
            ]),
        ]);

        $continuation = $service->process($index->id, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Completed, $index->state);
        $this->assertSame(self::STORE.'/documents/prime-file-17-relative', $index->google_document_name);
        Http::assertSentCount(1);
    }

    public function test_transient_status_read_failure_stays_retryable_and_bounded(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => 'pending',
            'google_file_id' => 'files/prime-file-18',
            'operation_name' => self::STORE.'/operations/import-18',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            $this->operationUrl('import-18') => Http::response(['error' => ['message' => 'overloaded']], 503),
        ]);

        $continuation = $service->process($index->id, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiIndexingContinuation::RetryStatusRead, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Pending, $index->state);
        $this->assertNull($index->safe_error_code);
        Http::assertSentCount(1);
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
            self::STORE_URL => Http::response(['name' => self::STORE]),
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
            self::STORE_URL => Http::response(['name' => self::STORE]),
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
        Http::assertSentCount(4);
    }

    public function test_document_identity_from_another_store_is_never_completed(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $index = $this->schedule($document);
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
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
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
            self::UPLOAD_URL.'/files' => Http::failedConnection(),
        ]);

        $service->process($index->id, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->state);
        $this->assertSame('upload_outcome_unknown', $index->safe_error_code);
        $this->assertNull($index->google_file_id);
        // Bukti tulis sudah tersimpan sebelum unggahan dikirim, jadi hasil yang
        // tidak pasti tetap dapat dikenali sebagai "mungkin sudah menulis".
        $this->assertSame('may_have_started', $index->remote_write_evidence->value);
        Http::assertSentCount(2);

        // Fake baru agar yang diperiksa hanya hasil pemanggilan schedule kedua.
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        $service->schedule($document);
        Queue::assertNothingPushed();
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->fresh()->state);
        // Penjadwalan ulang tidak boleh menurunkan bukti tulis generation ini.
        $this->assertSame('may_have_started', $index->fresh()->remote_write_evidence->value);
        Http::assertSentCount(2);
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

    public function test_permanent_status_response_parsing_error_fails_without_retrying(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $document = $this->document();
        $service = app(GeminiDocumentIndexingService::class);
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => 'pending',
            'google_file_id' => 'files/prime-file-15',
            'operation_name' => self::STORE.'/operations/import-15',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            $this->operationUrl('import-15') => Http::response([
                'name' => self::STORE.'/operations/import-15',
                'done' => true,
                'response' => ['documentName' => 'fileSearchStores/other-store/documents/doc-15'],
            ]),
        ]);

        $continuation = $service->process($index->id, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->state);
        $this->assertSame('provider_response_invalid', $index->safe_error_code);
        $this->assertNull($index->google_document_name);

        $service->schedule($document);

        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->fresh()->state);
        Http::assertSentCount(1);
    }

    public function test_failed_import_with_known_file_is_not_automatically_reimported(): void
    {
        $this->configureIndexing();
        $document = $this->document();
        $index = $this->schedule($document);
        $index->forceFill([
            'state' => GeminiDocumentIndexState::Failed,
            'google_file_id' => 'files/prime-file-8',
            'remote_write_evidence' => 'may_have_started',
        ])->save();
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Http::preventStrayRequests();

        app(GeminiDocumentIndexingService::class)->schedule($document);
        $this->process($index, $index->generation);

        $this->assertSame(1, $index->refresh()->generation);
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('files/prime-file-8', $index->google_file_id);
        $this->assertSame('may_have_started', $index->remote_write_evidence->value);
        $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_store_access_denied_blocks_upload_and_preserves_completed_index(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $historicDocument = $this->document();
        KnowledgeDocumentGeminiIndex::query()->create([
            'knowledge_document_id' => $historicDocument->id,
            'document_code' => $historicDocument->document_code,
            'document_version' => $historicDocument->version,
            'document_checksum' => $historicDocument->checksum,
            'store_id' => self::STORE,
            'google_file_id' => 'files/historic-1',
            'operation_name' => self::STORE.'/operations/historic-1',
            'google_document_name' => self::STORE.'/documents/historic-1',
            'state' => 'completed',
            'generation' => 1,
        ]);
        $index = $this->schedule($this->document());
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['error' => ['message' => 'detail provider rahasia']], 403)]);

        $continuation = $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('access_denied', $index->safe_error_code);
        $this->assertStringNotContainsString('rahasia', (string) $index->safe_error_message);
        $this->assertNull($index->google_file_id);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        Http::assertSentCount(1);

        $historic = KnowledgeDocumentGeminiIndex::query()
            ->where('knowledge_document_id', $historicDocument->id)
            ->sole();
        $this->assertSame(GeminiDocumentIndexState::Completed, $historic->state);
        $this->assertSame(self::STORE.'/documents/historic-1', $historic->google_document_name);
    }

    public function test_missing_store_blocks_upload_without_creating_a_store(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['error' => ['message' => 'not found']], 404)]);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('store_not_found', $index->safe_error_code);
        $this->assertNull($index->google_file_id);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::STORE_URL);
    }

    public function test_store_identity_mismatch_is_never_false_readiness(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['name' => 'fileSearchStores/other-store'])]);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('provider_response_invalid', $index->safe_error_code);
        $this->assertNull($index->google_file_id);
        $this->assertNull($index->google_document_name);
        Http::assertSentCount(1);
    }

    public function test_missing_chat_credential_blocks_upload_without_any_request(): void
    {
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        Http::preventStrayRequests();
        Http::fake();

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('credential_not_configured', $index->safe_error_code);
        Http::assertNothingSent();
    }

    public function test_temporary_store_failure_keeps_row_resumable_without_upload(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push(['name' => self::STORE])
                ->push(['name' => self::STORE]),
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-9']]),
            self::IMPORT_URL => Http::response(['name' => self::STORE.'/operations/import-9', 'done' => false]),
        ]);

        $continuation = $this->process($index, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::RetryStatusRead, $continuation);
        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Queued, $index->state);
        $this->assertNotNull($index->last_checked_at);
        $this->assertNull($index->google_file_id);
        $this->assertSame('provider_unavailable', $index->safe_error_code);
        $this->assertSame('Akses store Gemini File Search sedang tidak tersedia; belum ada unggahan yang terkirim.', $index->safe_error_message);
        Http::assertSentCount(1);

        $continuation = app(GeminiDocumentIndexingService::class)->process($index->id, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::AwaitRemoteCompletion, $continuation);
        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Pending, $index->state);
        $this->assertSame('files/prime-file-9', $index->google_file_id);
        Http::assertSentCount(5);
    }

    public function test_store_changed_after_scheduling_blocks_every_google_request(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        config(['gemini_file_search.indexing.store' => 'store-other-dev']);
        Http::preventStrayRequests();
        Http::fake();

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('store_changed', $index->safe_error_code);
        Http::assertNothingSent();
    }

    public function test_denied_store_on_uploaded_row_preserves_file_identity_without_reupload(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => 'uploaded',
            'google_file_id' => 'files/prime-file-10',
        ]);
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['error' => ['message' => 'denied']], 403)]);

        $continuation = app(GeminiDocumentIndexingService::class)->process($index->id, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('access_denied', $index->safe_error_code);
        $this->assertSame('files/prime-file-10', $index->google_file_id);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        Http::assertSentCount(1);
    }

    public function test_failed_queued_job_marks_index_terminally_failed(): void
    {
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        $job = new IndexKnowledgeDocumentInGemini($index->id, $index->generation);

        $job->failed(new MaxAttemptsExceededException('Attempts exhausted.'));

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('indexing_attempts_exhausted', $index->safe_error_code);
        $this->assertSame('Pemrosesan dokumen berhenti setelah beberapa percobaan. Periksa koneksi AI, lalu coba lagi.', $index->safe_error_message);
        $this->assertNull($index->google_file_id);
    }

    public function test_failed_uploaded_job_preserves_identity_without_starting_another_import(): void
    {
        $this->configureIndexing();
        $document = $this->document();
        $index = $this->schedule($document);
        $index->forceFill([
            'state' => GeminiDocumentIndexState::Uploaded,
            'google_file_id' => 'files/prime-file-exhausted',
            'remote_write_evidence' => 'may_have_started',
        ])->save();

        (new IndexKnowledgeDocumentInGemini($index->id, $index->generation))
            ->failed(new MaxAttemptsExceededException('Attempts exhausted.'));
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        app(GeminiDocumentIndexingService::class)->schedule($document);

        $this->assertSame(GeminiDocumentIndexState::Failed, $index->refresh()->state);
        $this->assertSame('indexing_attempts_exhausted', $index->safe_error_code);
        $this->assertSame('files/prime-file-exhausted', $index->google_file_id);
        $this->assertSame('may_have_started', $index->remote_write_evidence->value);
        $this->assertSame(1, $index->generation);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_retry_exhaustion_preserves_pending_completed_uncertain_and_stale_records(): void
    {
        $this->configureIndexing();
        Http::preventStrayRequests();
        $document = $this->document();
        $index = $this->schedule($document);

        foreach ([
            [GeminiDocumentIndexState::Pending, 1, 'files/prime-pending', self::STORE.'/operations/pending'],
            [GeminiDocumentIndexState::Completed, 1, 'files/prime-completed', self::STORE.'/operations/completed'],
            [GeminiDocumentIndexState::Unknown, 1, null, self::STORE.'/operations/unknown'],
            [GeminiDocumentIndexState::Uploading, 1, null, null],
            [GeminiDocumentIndexState::Importing, 1, 'files/prime-importing', null],
            [GeminiDocumentIndexState::Queued, 2, null, null],
        ] as [$state, $generation, $fileId, $operationName]) {
            KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
                'state' => $state->value,
                'generation' => $generation,
                'google_file_id' => $fileId,
                'operation_name' => $operationName,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ]);
            $failedGeneration = $generation === 2 ? 1 : $generation;
            (new IndexKnowledgeDocumentInGemini($index->id, $failedGeneration))->failed(new MaxAttemptsExceededException('Attempts exhausted.'));
            $afterExhaustion = $index->fresh();

            if ($generation === 2) {
                $this->assertSame(GeminiDocumentIndexState::Queued, $afterExhaustion->state);
                $this->assertNull($afterExhaustion->safe_error_code);

                continue;
            }

            if ($state === GeminiDocumentIndexState::Pending) {
                $this->assertSame(GeminiDocumentIndexState::Pending, $afterExhaustion->state);
                $this->assertSame('status_check_exhausted', $afterExhaustion->safe_error_code);
                $this->assertSame($operationName, $afterExhaustion->operation_name);

                continue;
            }

            $this->assertSame($state, $afterExhaustion->state);
            $this->assertSame($fileId, $afterExhaustion->google_file_id);
            $this->assertSame($operationName, $afterExhaustion->operation_name);
            $this->assertNull($afterExhaustion->safe_error_code);
        }

        Http::assertNothingSent();
    }

    public function test_credential_changed_after_scheduling_still_indexes_only_the_snapshotted_store(): void
    {
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        // Admin mengganti kredensial chat setelah job masuk antrean: worker
        // memakai kredensial aktif saat eksekusi, tetapi tetap terikat pada
        // store yang disnapshot dan tidak pernah menulis ke store lain.
        $this->activateGeminiRevision('fictional-rotated-gemini-key');
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-11']]),
            self::IMPORT_URL => Http::response(['name' => self::STORE.'/operations/import-11', 'done' => false]),
        ]);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Pending, $index->state);
        $this->assertSame(self::STORE, $index->store_id);
        $this->assertSame(self::STORE.'/operations/import-11', $index->operation_name);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::STORE_URL
            && $request->header('x-goog-api-key') === ['fictional-rotated-gemini-key']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->header('x-goog-api-key') === ['fictional-rotated-gemini-key']);
        Http::assertSentCount(4);
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

    public function test_upload_claim_is_refused_when_generation_changes_during_readiness_check(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => function () use ($index) {
                // Simulasi deterministik tanpa worker kedua: generation baru
                // dimulai tepat saat pemeriksaan akses store generation lama
                // masih berjalan, dan state barunya sama-sama `queued`.
                KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
                    'generation' => 2,
                    'state' => GeminiDocumentIndexState::Queued->value,
                ]);

                return Http::response(['name' => self::STORE]);
            },
        ]);

        $continuation = $this->process($index, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $newer = $index->fresh();
        $this->assertSame(2, $newer->generation);
        $this->assertSame(GeminiDocumentIndexState::Queued, $newer->state);
        $this->assertNull($newer->google_file_id);
        $this->assertNull($newer->safe_error_code);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::UPLOAD_URL.'/files');
        Http::assertSentCount(1);
    }

    public function test_import_claim_is_refused_when_generation_changes_during_readiness_check(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Uploaded->value,
            'google_file_id' => 'files/prime-stale-static',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => function () use ($index) {
                // Restart mempertahankan identitas berkas, sehingga state
                // generation baru juga `uploaded` dan job lama tidak boleh
                // mengimpor atas namanya.
                KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
                    'generation' => 2,
                    'state' => GeminiDocumentIndexState::Uploaded->value,
                ]);

                return Http::response(['name' => self::STORE]);
            },
        ]);

        $continuation = $this->process($index, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $newer = $index->fresh();
        $this->assertSame(2, $newer->generation);
        $this->assertSame(GeminiDocumentIndexState::Uploaded, $newer->state);
        $this->assertSame('files/prime-stale-static', $newer->google_file_id);
        $this->assertNull($newer->operation_name);
        $this->assertNull($newer->safe_error_code);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::IMPORT_URL);
        Http::assertSentCount(1);
    }

    public function test_uncertain_upload_after_generation_change_cannot_mark_newer_generation_unknown(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
            self::UPLOAD_URL.'/files' => function () use ($index) {
                // Generation berubah setelah klaim `uploading`; state klaim
                // dipertahankan agar hanya syarat generation yang menahan
                // penulisan hasil unggahan oleh job lama.
                KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['generation' => 2]);

                return Http::failedConnection();
            },
        ]);

        $this->process($index, $index->generation);

        $newer = $index->fresh();
        $this->assertSame(2, $newer->generation);
        $this->assertSame(GeminiDocumentIndexState::Uploading, $newer->state);
        $this->assertNull($newer->google_file_id);
        $this->assertNull($newer->safe_error_code);
        Http::assertSentCount(2);
    }

    public function test_upload_result_after_generation_change_is_not_recorded_on_newer_generation(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        Http::preventStrayRequests();
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
            self::UPLOAD_URL.'/files' => function () use ($index) {
                KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['generation' => 2]);

                return Http::response(['file' => ['name' => 'files/prime-stale-flight']]);
            },
        ]);

        $continuation = $this->process($index, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $newer = $index->fresh();
        $this->assertSame(2, $newer->generation);
        $this->assertSame(GeminiDocumentIndexState::Uploading, $newer->state);
        $this->assertNull($newer->google_file_id);
        $this->assertNull($newer->operation_name);
        $this->assertNull($newer->safe_error_code);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::IMPORT_URL);
        Http::assertSentCount(2);
    }

    public function test_inspection_failure_after_generation_change_cannot_fail_newer_generation(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Pending->value,
            'operation_name' => self::STORE.'/operations/import-stale-fail',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            $this->operationUrl('import-stale-fail') => function () use ($index) {
                KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['generation' => 2]);

                return Http::response([
                    'name' => self::STORE.'/operations/import-stale-fail',
                    'done' => true,
                    'error' => ['message' => 'gagal di sisi provider'],
                ]);
            },
        ]);

        $continuation = $this->process($index, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $newer = $index->fresh();
        $this->assertSame(2, $newer->generation);
        $this->assertSame(GeminiDocumentIndexState::Pending, $newer->state);
        $this->assertNull($newer->safe_error_code);
        $this->assertNull($newer->safe_error_message);
        Http::assertSentCount(1);
    }

    public function test_inspection_completion_after_generation_change_cannot_complete_newer_generation(): void
    {
        $this->activateGeminiRevision();
        $this->configureIndexing();
        $index = $this->schedule($this->document());
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Pending->value,
            'operation_name' => self::STORE.'/operations/import-stale-complete',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            $this->operationUrl('import-stale-complete') => function () use ($index) {
                KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['generation' => 2]);

                return Http::response([
                    'name' => self::STORE.'/operations/import-stale-complete',
                    'done' => true,
                    'response' => ['documentName' => self::STORE.'/documents/doc-stale'],
                ]);
            },
        ]);

        $continuation = $this->process($index, $index->generation);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $newer = $index->fresh();
        $this->assertSame(2, $newer->generation);
        $this->assertSame(GeminiDocumentIndexState::Pending, $newer->state);
        $this->assertNull($newer->google_document_name);
        Http::assertSentCount(1);
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
        // Kegagalan sebelum klaim tulis tidak boleh mengubah bukti tulis:
        // baris ini masih terbukti belum pernah mengirim permintaan Google.
        $this->assertSame('not_started', $index->remote_write_evidence->value);
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
        $content = "%PDF-1.4\nSynthetic PRIME SOP ".$document->document_code."\n";
        Storage::disk('local')->put($document->storage_path, $content);

        if (! array_key_exists('checksum', $overrides)) {
            // Checksum registry harus menggambarkan byte yang benar-benar
            // tersimpan; jalur unggah memverifikasi keduanya sebelum ke Google.
            $document->forceFill(['checksum' => hash('sha256', $content)])->save();
        }

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

    private function activateGeminiRevision(string $credential = self::CREDENTIAL): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Fictional Gemini',
            'purpose' => 'chat',
        ]);
        $revision = $configuration->revisions()->make([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.8-flash',
            'credential' => $credential,
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
