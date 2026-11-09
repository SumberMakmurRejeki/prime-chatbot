<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\GeminiDocumentIndexingService;
use App\Services\AI\GeminiDocumentIndexState;
use App\Services\AI\GeminiIndexingContinuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_new_import_generation_gets_a_new_identity_and_clears_previous_provenance(): void
    {
        $this->activateGeminiRevision();
        $document = $this->document();
        $index = $this->schedule($document);
        $this->fakeImport('import-3', done: false);
        $this->process($index, $index->generation);

        $previousIdentity = (string) $index->refresh()->import_identity;
        KnowledgeDocumentGeminiIndex::query()->whereKey($index->id)->update(['state' => GeminiDocumentIndexState::Failed->value]);

        $this->schedule($document);

        $restarted = $index->refresh();
        $this->assertSame(2, $restarted->generation);
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
        Storage::disk('local')->put($document->storage_path, "%PDF-1.4\nSynthetic PRIME SOP\n");

        return $document;
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
