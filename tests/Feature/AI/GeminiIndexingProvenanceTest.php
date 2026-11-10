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
use App\Services\AI\GeminiRetrievalEligibilityService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Provenance kredensial dan identitas impor unik pada indeksasi Gemini.
 *
 * Bukti diambil dari baris indeksasi di MySQL/SQLite dan dari payload impor
 * yang benar-benar diserialisasi (Http::fake), bukan dari helper internal.
 * Tidak ada panggilan Gemini/Qdrant nyata dan tidak ada panggilan jaringan liar.
 */
class GeminiIndexingProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private const CREDENTIAL = 'fictional-prime-gemini-key';

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta';

    private const STORE = 'fileSearchStores/store-prime-dev';

    private const STORE_URL = self::BASE_URL.'/fileSearchStores/store-prime-dev';

    private const IMPORT_URL = self::BASE_URL.'/fileSearchStores/store-prime-dev:importFile';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        Http::preventStrayRequests();
        config([
            'gemini_file_search.indexing.enabled' => true,
            'gemini_file_search.indexing.store' => 'store-prime-dev',
            'queue.default' => 'database',
        ]);
    }

    public function test_recovery_and_schedule_reject_failed_rows_without_identity_and_trusted_write_evidence(): void
    {
        $this->activateGeminiRevision();

        foreach ([null, 'may_have_started'] as $evidence) {
            foreach (['recover', 'schedule'] as $action) {
                $document = $this->document();
                $index = $this->schedule($document);
                KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
                    'state' => GeminiDocumentIndexState::Failed->value,
                    'remote_write_evidence' => $evidence,
                    'google_file_id' => null,
                    'operation_name' => null,
                    'google_document_name' => null,
                ]);
                Queue::fake([IndexKnowledgeDocumentInGemini::class]);

                if ($action === 'recover') {
                    try {
                        app(GeminiDocumentIndexingService::class)->recover($document);
                        $this->fail('Recovery harus menolak bukti tulis yang tidak aman.');
                    } catch (DomainException $exception) {
                        $this->assertSame(
                            'Bukti tulis Google pada generation lama tidak diketahui atau tidak menjamin keamanan; pemulihan ditolak tanpa unggahan baru.',
                            $exception->getMessage(),
                        );
                    }
                } else {
                    app(GeminiDocumentIndexingService::class)->schedule($document);
                }

                // Kedua jalur harus menolak sebelum ada permintaan Google: tidak
                // ada generation baru, history, job, maupun koneksi keluar.
                $this->assertSame(1, $index->refresh()->generation);
                $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
                $this->assertSame($evidence, $index->getRawOriginal('remote_write_evidence'));
                $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
                Queue::assertNothingPushed();
                Http::assertNothingSent();
            }
        }
    }

    public function test_recovery_starts_a_fresh_generation_from_verified_source_without_remote_identity(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Failed->value,
            'remote_write_evidence' => 'not_started',
            'safe_error_code' => 'store_changed',
            'credential_configuration_id' => null,
            'credential_revision_id' => null,
            'import_identity' => null,
        ]);
        $legacyChecksum = $document->checksum;
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        $recovered = app(GeminiDocumentIndexingService::class)->recover($document);

        $this->assertSame(2, $recovered->generation);
        $this->assertSame(GeminiDocumentIndexState::Queued, $recovered->state);
        $this->assertSame($legacyChecksum, $recovered->document_checksum);
        $this->assertNull($recovered->google_file_id);
        $this->assertNull($recovered->google_document_name);
        $this->assertNull($recovered->credential_revision_id);
        $this->assertNull($recovered->credential_configuration_id);
        $this->assertSame('not_started', $index->fresh()->remote_write_evidence->value);
        $this->assertSame('not_started', $recovered->remote_write_evidence->value);
        $this->assertIsString($recovered->import_identity);
        $this->assertNull($recovered->safe_error_code);
        $this->assertDatabaseHas('knowledge_document_gemini_indexing_generations', [
            'knowledge_document_gemini_indexing_id' => $index->id,
            'generation' => 1,
            'state' => 'failed',
            'import_identity' => null,
            'credential_revision_id' => null,
        ]);
        Queue::assertPushed(
            IndexKnowledgeDocumentInGemini::class,
            fn (IndexKnowledgeDocumentInGemini $job): bool => $job->indexId === $index->id && $job->generation === 2,
        );
        Http::assertNothingSent();
    }

    public function test_recovered_generation_records_only_the_provenance_of_its_own_import(): void
    {
        $revision = $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Failed->value,
            'remote_write_evidence' => 'not_started',
            'import_identity' => null,
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        $recovered = app(GeminiDocumentIndexingService::class)->recover($document);
        $this->fakeImport('import-recovery', done: false);

        $this->process($recovered, $recovered->generation);

        $recovered->refresh();
        $this->assertSame(GeminiDocumentIndexState::Pending, $recovered->state);
        $this->assertSame($revision->id, $recovered->credential_revision_id);
        $this->assertSame($revision->configuration_id, $recovered->credential_configuration_id);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::IMPORT_URL
            && $request['customMetadata'] === [
                ['key' => 'document_code', 'stringValue' => $document->document_code],
                ['key' => 'version', 'stringValue' => $document->version],
                ['key' => 'import_identity', 'stringValue' => $recovered->import_identity],
            ]);
    }

    public function test_recovery_resumes_a_stored_operation_by_polling_without_a_new_write(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Pending->value,
            'google_file_id' => 'files/legacy-file-1',
            'operation_name' => self::STORE.'/operations/legacy-op',
            'remote_write_evidence' => null,
            'import_identity' => null,
            'credential_configuration_id' => null,
            'credential_revision_id' => null,
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        $resumed = app(GeminiDocumentIndexingService::class)->recover($document);

        $this->assertSame(1, $resumed->generation);
        $this->assertSame(GeminiDocumentIndexState::Pending, $resumed->state);
        $this->assertNull($resumed->remote_write_evidence);
        $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
        Queue::assertPushed(
            IndexKnowledgeDocumentInGemini::class,
            fn (IndexKnowledgeDocumentInGemini $job): bool => $job->indexId === $index->id && $job->generation === 1,
        );
        Http::assertNothingSent();

        $this->fakeOperationCompletion('legacy-op', 'legacy-doc');
        $this->process($resumed, $resumed->generation);

        $completed = $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Completed, $completed->state);
        $this->assertSame(self::STORE.'/documents/legacy-doc', $completed->google_document_name);
        // GET tidak membuktikan asal impor lama ataupun apakah mutasi pernah
        // dimulai; polling tidak boleh mengarang provenance maupun bukti tulis.
        $this->assertNull($completed->credential_revision_id);
        $this->assertNull($completed->credential_configuration_id);
        $this->assertNull($completed->import_identity);
        $this->assertNull($completed->remote_write_evidence);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::UPLOAD_URL.'/files'
            || $request->url() === self::IMPORT_URL);
    }

    public function test_recovery_refuses_a_completed_generation_whose_google_document_still_exists(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Completed->value,
            'google_file_id' => 'files/legacy-file-1',
            'google_document_name' => self::STORE.'/documents/legacy-doc',
            'import_identity' => null,
            'credential_configuration_id' => null,
            'credential_revision_id' => null,
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Generation lama masih memiliki dokumen di store Google dan tidak dihapus otomatis; generation baru ditolak untuk mencegah resource ganda.');

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
        } finally {
            $untouched = $index->refresh();
            $this->assertSame(1, $untouched->generation);
            $this->assertSame(GeminiDocumentIndexState::Completed, $untouched->state);
            $this->assertSame(self::STORE.'/documents/legacy-doc', $untouched->google_document_name);
            $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_recovery_rejects_a_missing_original_pdf_before_any_mutation(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Storage::disk('local')->delete($document->storage_path);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Berkas PDF sumber tidak ditemukan di storage privat; tidak ada permintaan Google yang dikirim.');

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
        } finally {
            $this->assertSame(1, $index->refresh()->generation);
            $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_recovery_rejects_a_source_pdf_that_no_longer_matches_the_registry_checksum(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Storage::disk('local')->put($document->storage_path, "%PDF-1.4\nReplaced SOP\n");

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Checksum berkas PDF sumber tidak cocok dengan checksum registry; tidak ada permintaan Google yang dikirim.');

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
        } finally {
            $this->assertSame(1, $index->refresh()->generation);
            $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_recovery_refuses_an_unknown_outcome_without_a_second_write(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Unknown->value,
            'google_file_id' => 'files/legacy-file-1',
            'safe_error_code' => 'upload_outcome_unknown',
            'safe_error_message' => 'Unggahan ke Google tidak dapat dipastikan; indeksasi menunggu pemeriksaan manual.',
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Hasil generation lama tidak dapat dipastikan; pemulihan tidak dimulai tanpa operasi yang dapat diperiksa.');

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
        } finally {
            $untouched = $index->refresh();
            $this->assertSame(1, $untouched->generation);
            $this->assertSame(GeminiDocumentIndexState::Unknown, $untouched->state);
            $this->assertSame('upload_outcome_unknown', $untouched->safe_error_code);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_recovery_refuses_a_live_claim_and_settles_a_stale_one(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Importing->value,
            'google_file_id' => 'files/legacy-file-1',
            'updated_at' => now(),
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
            $this->fail('Klaim aktif harus ditolak.');
        } catch (DomainException $exception) {
            $this->assertSame('Percobaan indeksasi lain masih berjalan; pemulihan tidak dapat dipastikan aman.', $exception->getMessage());
        }

        $this->assertSame(GeminiDocumentIndexState::Importing, $index->refresh()->state);
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        // Klaim basi berarti worker mati di tengah operasi tulis: hasilnya tidak
        // diketahui, jadi baris dipindahkan ke `unknown` dan tidak diulang.
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['updated_at' => now()->subHour()]);

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
            $this->fail('Klaim basi harus ditolak.');
        } catch (DomainException $exception) {
            $this->assertSame('Hasil generation lama tidak dapat dipastikan; pemulihan tidak dimulai tanpa operasi yang dapat diperiksa.', $exception->getMessage());
        }

        $settled = $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Unknown, $settled->state);
        $this->assertSame('stale_attempt', $settled->safe_error_code);
        $this->assertSame(1, $settled->generation);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_recovery_rejects_a_changed_document_identity(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);
        $document->forceFill(['version' => '9.9'])->save();
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Identitas atau checksum dokumen berubah sejak indeksasi dijadwalkan; pemulihan ditolak.');

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
        } finally {
            $this->assertSame(1, $index->refresh()->generation);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_recovery_leaves_a_verified_published_index_untouched(): void
    {
        $revision = $this->activateGeminiRevision();
        $document = $this->document(['is_published' => true, 'published_at' => now()->subDay(), 'status' => 'ready']);
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Completed->value,
            'google_file_id' => 'files/live-file-1',
            'google_document_name' => self::STORE.'/documents/live-doc',
            'credential_configuration_id' => $revision->configuration_id,
            'credential_revision_id' => $revision->id,
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Indeks Gemini dokumen ini sudah terverifikasi; pemulihan tidak diperlukan.');

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
        } finally {
            $untouched = $index->refresh();
            $this->assertSame(1, $untouched->generation);
            $this->assertSame(GeminiDocumentIndexState::Completed, $untouched->state);
            $this->assertSame(self::STORE.'/documents/live-doc', $untouched->google_document_name);
            $this->assertFalse($document->refresh()->published_at === null);
            $this->assertTrue($document->is_published);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_recovery_never_publishes_the_document(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Failed->value,
            'remote_write_evidence' => 'not_started',
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        app(GeminiDocumentIndexingService::class)->recover($document);

        $unchanged = $document->refresh();
        $this->assertFalse($unchanged->is_published);
        $this->assertNull($unchanged->published_at);
        $this->assertNull($unchanged->published_by);
        Queue::assertNotPushed(ProcessKnowledgeDocument::class);
    }

    public function test_recovery_requires_an_enabled_and_asynchronous_configuration(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        config(['gemini_file_search.indexing.enabled' => false]);

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
            $this->fail('Indeksasi nonaktif harus ditolak.');
        } catch (DomainException $exception) {
            $this->assertSame('Indeksasi Gemini File Search tidak aktif; pemulihan tidak dijalankan.', $exception->getMessage());
        }

        config(['gemini_file_search.indexing.enabled' => true, 'queue.default' => 'sync']);

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
            $this->fail('Queue sync harus ditolak.');
        } catch (DomainException $exception) {
            $this->assertSame('Queue driver sync tidak aman untuk indeksasi Gemini; gunakan queue asinkron.', $exception->getMessage());
        }

        $this->assertSame(1, $index->refresh()->generation);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_generation_bumped_by_recovery_ignores_the_previous_job(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        $recovered = app(GeminiDocumentIndexingService::class)->recover($document);

        $continuation = $this->process($recovered, 1);

        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $this->assertSame(2, $recovered->refresh()->generation);
        $this->assertSame(GeminiDocumentIndexState::Queued, $recovered->state);
        Http::assertNothingSent();
    }

    public function test_recovery_rejects_an_unverifiable_gemini_credential_context(): void
    {
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
            $this->fail('Kredensial yang tidak dapat diresolusi harus ditolak.');
        } catch (DomainException $exception) {
            $this->assertSame('Kredensial Gemini aktif tidak terverifikasi; pemulihan ditolak.', $exception->getMessage());
        }

        $untouched = $index->refresh();
        $this->assertSame(1, $untouched->generation);
        $this->assertSame(GeminiDocumentIndexState::Failed, $untouched->state);
        $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_failed_recovery_of_another_document_keeps_a_published_sop_intact(): void
    {
        $revision = $this->activateGeminiRevision();
        $published = $this->document(['document_code' => 'FNC-PUBLISHED', 'is_published' => true, 'published_at' => now()->subDay()]);
        $publishedIndex = $this->schedule($published);
        KnowledgeDocumentGeminiIndex::query()->whereKey($publishedIndex->id)->update([
            'state' => GeminiDocumentIndexState::Completed->value,
            'google_file_id' => 'files/live-file-1',
            'google_document_name' => self::STORE.'/documents/live-doc',
            'credential_configuration_id' => $revision->configuration_id,
            'credential_revision_id' => $revision->id,
        ]);
        $failed = $this->document(['document_code' => 'FNC-FAILED']);
        $failedIndex = $this->schedule($failed);
        KnowledgeDocumentGeminiIndex::query()->whereKey($failedIndex->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);
        Storage::disk('local')->delete($failed->storage_path);

        try {
            app(GeminiDocumentIndexingService::class)->recover($failed);
            $this->fail('Sumber yang hilang harus ditolak.');
        } catch (DomainException) {
            // Kegagalan pemulihan dokumen lain tidak boleh menyentuh SOP terbit.
        }

        $this->assertTrue($published->refresh()->is_published);
        $this->assertNotNull($published->published_at);
        $this->assertSame(GeminiDocumentIndexState::Completed, $publishedIndex->refresh()->state);
        $this->assertSame(self::STORE.'/documents/live-doc', $publishedIndex->google_document_name);
        $this->assertSame(1, $failedIndex->refresh()->generation);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_upload_refuses_a_source_file_that_drifted_from_the_registry_checksum(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        Storage::disk('local')->put($document->storage_path, "%PDF-1.4\nReplaced SOP\n");

        $this->process($index, $index->generation);

        $failed = $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $failed->state);
        $this->assertSame('source_checksum_mismatch', $failed->safe_error_code);
        $this->assertNull($failed->google_file_id);
        Http::assertNothingSent();
    }

    public function test_ambiguous_upload_cannot_be_reclassified_as_recoverable_after_store_changes(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Unknown->value,
            'safe_error_code' => 'upload_outcome_unknown',
        ]);
        config(['gemini_file_search.indexing.store' => 'different-store']);

        $this->process($index, 1);
        config(['gemini_file_search.indexing.store' => 'store-prime-dev']);

        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->refresh()->state);
        $this->assertSame('upload_outcome_unknown', $index->safe_error_code);
        Queue::fake();

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
            $this->fail('An ambiguous upload must never become a fresh upload.');
        } catch (DomainException) {
        }

        $this->assertSame(1, $index->refresh()->generation);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_in_flight_upload_remains_ambiguous_when_document_identity_changes(): void
    {
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Uploading->value]);
        $document->forceFill(['version' => 'changed'])->save();

        $this->process($index, 1);

        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->refresh()->state);
        $this->assertSame('outcome_unknown', $index->safe_error_code);
        Http::assertNothingSent();
    }

    public function test_failed_generation_with_a_known_google_file_cannot_be_retried_as_a_second_import(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Failed->value,
            'google_file_id' => 'files/legacy-file-1',
            'safe_error_code' => 'legacy_import_untrusted',
            'import_identity' => null,
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        try {
            app(GeminiDocumentIndexingService::class)->recover($document);
            $this->fail('Known remote resources must not be re-imported.');
        } catch (DomainException) {
        }

        $this->assertSame(GeminiDocumentIndexState::Failed, $index->refresh()->state);
        $this->assertSame('files/legacy-file-1', $index->google_file_id);
        $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_upload_claim_persists_may_have_started_before_the_google_request(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        $observedClaim = false;
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
            self::UPLOAD_URL.'/files' => function () use ($index, &$observedClaim) {
                $claimed = $index->fresh();
                $this->assertSame(GeminiDocumentIndexState::Uploading, $claimed->state);
                $this->assertSame('may_have_started', $claimed->remote_write_evidence->value);
                $observedClaim = true;

                return Http::failedConnection();
            },
        ]);

        $this->process($index, $index->generation);

        $this->assertTrue($observedClaim);
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->refresh()->state);
        $this->assertSame('may_have_started', $index->remote_write_evidence->value);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::IMPORT_URL);
    }

    public function test_import_claim_upgrades_legacy_null_evidence_before_the_google_request(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Uploaded->value,
            'google_file_id' => 'files/legacy-uploaded',
            'remote_write_evidence' => null,
        ]);
        $this->assertNull($index->refresh()->remote_write_evidence);
        $observedClaim = false;
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
            self::IMPORT_URL => function () use ($index, &$observedClaim) {
                $claimed = $index->fresh();
                $this->assertSame(GeminiDocumentIndexState::Importing, $claimed->state);
                $this->assertSame('may_have_started', $claimed->remote_write_evidence->value);
                $this->assertSame('files/legacy-uploaded', $claimed->google_file_id);
                $observedClaim = true;

                return Http::failedConnection();
            },
        ]);

        $this->process($index, $index->generation);

        $this->assertTrue($observedClaim);
        $this->assertSame(GeminiDocumentIndexState::Unknown, $index->refresh()->state);
        $this->assertSame('may_have_started', $index->remote_write_evidence->value);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::UPLOAD_URL.'/files');
    }

    public function test_import_records_non_secret_credential_provenance_and_identity_metadata(): void
    {
        $revision = $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        $this->fakeImport('import-1', done: false);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame($revision->id, $index->credential_revision_id);
        $this->assertSame($revision->configuration_id, $index->credential_configuration_id);
        $this->assertIsString($index->import_identity);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/', (string) $index->import_identity);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::IMPORT_URL
            && $request['customMetadata'] === [
                ['key' => 'document_code', 'stringValue' => $document->document_code],
                ['key' => 'version', 'stringValue' => $document->version],
                ['key' => 'import_identity', 'stringValue' => $index->import_identity],
            ]);

        // Kredensial hanya boleh hadir di header permintaan, tidak pernah di
        Http::assertNotSent(fn (Request $request): bool => isset($request->data()['customMetadata'])
            && str_contains(json_encode($request['customMetadata']), self::CREDENTIAL));
        $this->assertStringNotContainsString(self::CREDENTIAL, json_encode($index->getAttributes()));
    }

    public function test_resumed_pending_operation_keeps_import_identity_and_provenance(): void
    {
        $revision = $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        $this->fakeImport('import-2', done: false);
        $this->process($index, $index->generation);

        $pending = $index->refresh();
        $identity = $pending->import_identity;
        $this->assertSame(GeminiDocumentIndexState::Pending, $pending->state);

        $this->fakeOperationCompletion('import-2', 'doc-complete');
        $continuation = $this->process($index, $index->generation);

        $completed = $index->refresh();
        $this->assertSame(GeminiIndexingContinuation::Settled, $continuation);
        $this->assertSame(GeminiDocumentIndexState::Completed, $completed->state);
        $this->assertSame($identity, $completed->import_identity);
        $this->assertSame($revision->id, $completed->credential_revision_id);
        $this->assertSame($revision->configuration_id, $completed->credential_configuration_id);
    }

    public function test_failed_generation_snapshot_is_preserved_on_restart(): void
    {
        $document = $this->document();
        $publisher = User::factory()->create();
        $document->forceFill([
            'is_published' => true,
            'published_at' => '2026-11-01 08:00:00',
            'published_by' => $publisher->id,
        ])->save();
        $index = $this->schedule($document);
        $previousIdentity = $index->import_identity;
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Failed->value,
            'remote_write_evidence' => 'not_started',
            'safe_error_code' => 'remote_failed',
            'safe_error_message' => 'Kegagalan impor sebelumnya.',
            'created_at' => '2026-11-02 09:00:00',
            'updated_at' => '2026-11-03 10:00:00',
        ]);

        $this->schedule($document);

        // Snapshot arsip adalah satu-satunya tempat bukti generasi lama tetap
        // hidup: baris aktif menerima bukti awal generasi barunya sendiri.
        $this->assertDatabaseHas('knowledge_document_gemini_indexing_generations', [
            'knowledge_document_id' => $document->id,
            'knowledge_document_gemini_indexing_id' => $index->id,
            'document_code' => $document->document_code,
            'document_version' => $document->version,
            'document_checksum' => $document->checksum,
            'store_id' => self::STORE,
            'safe_error_message' => 'Kegagalan impor sebelumnya.',
            'current_created_at' => '2026-11-02 09:00:00',
            'current_updated_at' => '2026-11-03 10:00:00',
            'generation' => 1,
            'state' => 'failed',
            'remote_write_evidence' => 'not_started',
            'google_file_id' => null,
            'operation_name' => null,
            'google_document_name' => null,
            'import_identity' => $previousIdentity,
            'credential_revision_id' => null,
            'safe_error_code' => 'remote_failed',
            'last_checked_at' => null,
        ]);
        $restarted = $index->refresh();
        $this->assertSame(2, $restarted->generation);
        $this->assertSame('not_started', $restarted->remote_write_evidence->value);
        $this->assertDatabaseHas('knowledge_document_gemini_indexings', [
            'id' => $index->id,
            'state' => GeminiDocumentIndexState::Queued->value,
        ]);
        $currentDocument = $document->fresh();
        $this->assertTrue($currentDocument->is_published);
        $this->assertSame('2026-11-01 08:00:00', $currentDocument->published_at->format('Y-m-d H:i:s'));
        $this->assertSame($publisher->id, $currentDocument->published_by);
    }

    public function test_restart_is_refused_for_failed_rows_holding_google_identities(): void
    {
        $document = $this->document();
        $this->activateGeminiRevision();
        foreach ([
            ['google_file_id' => 'files/known', 'operation_name' => null, 'google_document_name' => null],
            ['google_file_id' => null, 'operation_name' => self::STORE.'/operations/known', 'google_document_name' => null],
            ['google_file_id' => null, 'operation_name' => null, 'google_document_name' => self::STORE.'/documents/known'],
        ] as $identity) {
            $document = $this->document();
            $index = $this->schedule($document);
            KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
                'state' => GeminiDocumentIndexState::Failed->value,
                'remote_write_evidence' => 'not_started',
                ...$identity,
            ]);
            Queue::fake([IndexKnowledgeDocumentInGemini::class]);

            $this->schedule($document);

            $this->assertSame(1, $index->refresh()->generation);
            $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
            $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_history_blocks_deleting_the_document_that_owns_it(): void
    {
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => 'failed']);
        $this->schedule($document);

        $this->expectException(QueryException::class);

        $document->delete();
    }

    public function test_rolled_back_generation_transition_leaves_no_history(): void
    {
        $document = $this->document();
        $index = $this->failedIndex($document);
        $queued = [];
        Event::listen(JobQueued::class, function (JobQueued $event) use (&$queued): void {
            $queued[] = $event->job;
        });
        $service = app(GeminiDocumentIndexingService::class);

        // Tanpa Queue::fake: transaksi yang gagal harus membuang history DAN
        // dispatch yang sudah dijadwalkan sesudah commit.
        try {
            DB::transaction(function () use ($service, $document): void {
                $service->schedule($document);

                throw new RuntimeException('rollback transisi generation');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback transisi generation', $exception->getMessage());
        }

        $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
        $this->assertSame(1, $index->fresh()->generation);
        $this->assertSame([], $queued);
        $this->assertSame('not_started', $index->fresh()->remote_write_evidence->value);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_rolled_back_recovery_leaves_no_history_and_no_queued_job(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->failedIndex($document);
        $queued = [];
        Event::listen(JobQueued::class, function (JobQueued $event) use (&$queued): void {
            $queued[] = $event->job;
        });

        try {
            DB::transaction(function () use ($document): void {
                app(GeminiDocumentIndexingService::class)->recover($document);

                throw new RuntimeException('rollback pemulihan');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback pemulihan', $exception->getMessage());
        }

        $this->assertSame(1, $index->refresh()->generation);
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
        $this->assertSame([], $queued);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_history_rejects_duplicate_document_and_generation(): void
    {
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => 'failed']);
        $this->schedule($document);
        $snapshot = (array) DB::table('knowledge_document_gemini_indexing_generations')->sole();
        unset($snapshot['id']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('knowledge_document_gemini_indexing_generations')->insert($snapshot);
    }

    public function test_history_rows_never_become_retrieval_or_publication_evidence(): void
    {
        $revision = $this->activateGeminiRevision();
        $document = $this->document(['status' => 'ready', 'is_published' => true]);
        $index = $this->schedule($document);
        DB::table('knowledge_document_gemini_indexing_generations')->insert([
            'knowledge_document_id' => $document->id,
            'knowledge_document_gemini_indexing_id' => $index->id,
            'document_code' => $index->document_code,
            'document_version' => $index->document_version,
            'document_checksum' => $index->document_checksum,
            'store_id' => self::STORE,
            'google_file_id' => 'files/history-eligible',
            'google_document_name' => self::STORE.'/documents/history-eligible',
            'state' => 'completed',
            'generation' => 1,
            'credential_configuration_id' => $revision->configuration_id,
            'credential_revision_id' => $revision->id,
            'import_identity' => 'history-import-identity',
            'archived_at' => now(),
        ]);
        $eligibility = app(GeminiRetrievalEligibilityService::class);

        $this->assertCount(0, $eligibility->eligibleCandidates());
        $this->assertNull($eligibility->trustedIndexFor($document));
    }

    public function test_completed_index_without_generation_transition_is_not_archived(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Completed->value,
            'google_file_id' => 'files/done',
            'google_document_name' => self::STORE.'/documents/done',
        ]);

        $this->schedule($document);

        $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
        $current = $index->fresh();
        $this->assertSame(1, $current->generation);
        $this->assertSame(GeminiDocumentIndexState::Completed, $current->state);
    }

    public function test_failed_row_with_a_previous_import_is_not_restarted_into_a_second_import(): void
    {
        $revision = $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        $this->fakeImport('import-3', done: false);
        $this->process($index, $index->generation);
        $imported = $index->refresh();
        $requestsBeforeRetry = Http::recorded()->count();

        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        $this->schedule($document);

        // Generasi yang sudah mengirim impor tidak boleh digantikan otomatis:
        // penggantian berarti impor kedua yang dapat menduplikasi dokumen.
        $unchanged = $index->refresh();
        $this->assertSame(1, $unchanged->generation);
        $this->assertSame(GeminiDocumentIndexState::Failed, $unchanged->state);
        $this->assertSame('may_have_started', $unchanged->remote_write_evidence->value);
        $this->assertSame($imported->import_identity, $unchanged->import_identity);
        $this->assertSame($imported->operation_name, $unchanged->operation_name);
        $this->assertSame($revision->id, $unchanged->credential_revision_id);
        $this->assertDatabaseCount('knowledge_document_gemini_indexing_generations', 0);
        Queue::assertNothingPushed();
        $this->assertSame($requestsBeforeRetry, Http::recorded()->count());
    }

    public function test_safe_fresh_generation_receives_a_new_import_identity_and_no_provenance(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        $previousIdentity = (string) $index->import_identity;
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Failed->value,
            'remote_write_evidence' => 'not_started',
            'google_file_id' => null,
            'operation_name' => null,
            'google_document_name' => null,
        ]);
        Queue::fake([IndexKnowledgeDocumentInGemini::class]);

        $this->schedule($document);

        $restarted = $index->refresh();
        $this->assertSame(2, $restarted->generation);
        $this->assertSame('not_started', $restarted->remote_write_evidence->value);
        $this->assertIsString($restarted->import_identity);
        $this->assertNotSame($previousIdentity, $restarted->import_identity);
        $this->assertNull($restarted->credential_revision_id);
        $this->assertNull($restarted->credential_configuration_id);

        $this->fakeImport('import-4', done: false);
        $this->process($restarted, $restarted->generation);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::IMPORT_URL
            && $request['customMetadata'] === [
                ['key' => 'document_code', 'stringValue' => $document->document_code],
                ['key' => 'version', 'stringValue' => $document->version],
                ['key' => 'import_identity', 'stringValue' => $restarted->import_identity],
            ]);
    }

    public function test_import_identity_is_a_random_token_not_derived_from_document_identity(): void
    {
        $this->activateGeminiRevision();
        $first = $this->schedule($this->document(['document_code' => 'SOP-DUP-1', 'version' => '2.0']));
        $second = $this->schedule($this->document(['document_code' => 'SOP-DUP-2', 'version' => '2.0']));

        // Token acak per baris: bukan turunan kode/versi/checksum, sehingga
        // impor ulang dokumen yang sama tidak pernah berbagi identitas.
        $this->assertMatchesRegularExpression('/^g[0-9a-f]{48}$/', (string) $first->import_identity);
        $this->assertNotSame($first->import_identity, $second->import_identity);
        Http::assertNothingSent();
    }

    public function test_provenance_records_the_revision_whose_credential_sent_the_import(): void
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Fictional Gemini',
            'purpose' => 'chat',
        ]);
        $this->storeRevision($configuration, 1, 'fictional-superseded-key');
        $active = $this->storeRevision($configuration, 2, 'fictional-active-key');
        $configuration->forceFill(['active_revision_id' => $active->id])->save();

        $document = $this->document();
        $index = $this->schedule($document);
        $this->fakeImport('import-provenance', done: false);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame($active->id, $index->credential_revision_id);
        $this->assertSame($configuration->id, $index->credential_configuration_id);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::IMPORT_URL
            && $request->header('x-goog-api-key') === ['fictional-active-key']);
        Http::assertNotSent(fn (Request $request): bool => $request->header('x-goog-api-key') === ['fictional-superseded-key']);
    }

    public function test_import_without_stored_identity_fails_closed_before_any_request(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update([
            'state' => GeminiDocumentIndexState::Uploaded->value,
            'google_file_id' => 'files/legacy-file-1',
            'import_identity' => null,
        ]);

        $this->process($index, $index->generation);

        $index->refresh();
        $this->assertSame(GeminiDocumentIndexState::Failed, $index->state);
        $this->assertSame('import_identity_missing', $index->safe_error_code);
        Http::assertNothingSent();
    }

    private function fakeImport(string $operation, bool $done, ?string $documentName = null): void
    {
        Http::fake([
            self::STORE_URL => Http::response(['name' => self::STORE]),
            self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/prime-file-'.str_replace('import-', '', $operation)]]),
            self::IMPORT_URL => Http::response(array_filter([
                'name' => self::STORE.'/operations/'.$operation,
                'done' => $done,
                'response' => $documentName === null ? null : ['documentName' => $documentName],
            ])),
        ]);
    }

    private function fakeOperationCompletion(string $operation, string $document): void
    {
        Http::fake([
            self::BASE_URL.'/'.self::STORE.'/operations/'.$operation => Http::response([
                'name' => self::STORE.'/operations/'.$operation,
                'done' => true,
                'response' => ['documentName' => self::STORE.'/documents/'.$document],
            ]),
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

    /** @param array<string, mixed> $attributes */
    private function failedIndex(KnowledgeDocument $document, array $attributes = []): KnowledgeDocumentGeminiIndex
    {
        // Dibuat langsung tanpa helper schedule(): test transaksi di bawah
        // butuh antrean sungguhan, bukan Queue::fake.
        return KnowledgeDocumentGeminiIndex::query()->create([
            'knowledge_document_id' => $document->id,
            'document_code' => $document->document_code,
            'document_version' => $document->version,
            'document_checksum' => $document->checksum,
            'store_id' => self::STORE,
            'state' => GeminiDocumentIndexState::Failed->value,
            'generation' => 1,
            // Baris hasil mesin selalu punya bukti tulis eksplisit; test legacy
            // memberi NULL secara eksplisit lewat $attributes.
            'remote_write_evidence' => 'not_started',
            'safe_error_code' => 'store_changed',
            ...$attributes,
        ]);
    }

    private function schedule(KnowledgeDocument $document): KnowledgeDocumentGeminiIndex
    {
        Queue::fake();
        app(GeminiDocumentIndexingService::class)->schedule($document);

        return KnowledgeDocumentGeminiIndex::query()->where('knowledge_document_id', $document->id)->sole();
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
        $revision = $this->storeRevision($configuration, 1, self::CREDENTIAL);
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function storeRevision(AiProviderConfiguration $configuration, int $number, string $credential): AiProviderConfigurationRevision
    {
        $revision = $configuration->revisions()->make([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.8-flash',
            'credential' => $credential,
            'enabled' => true,
        ])->forceFill([
            'revision_number' => $number,
            'test_state' => 'passed',
            'test_attempt_id' => 'fictional-attempt-'.$number,
            'test_started_at' => now(),
            'tested_at' => now(),
        ]);
        $revision->save();

        return $revision;
    }
}
