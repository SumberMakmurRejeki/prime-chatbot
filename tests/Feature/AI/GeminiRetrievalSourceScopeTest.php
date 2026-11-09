<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\GeminiDocumentMetadata;
use App\Services\AI\GeminiFileSearchConnection;
use App\Services\AI\GeminiRetrievalCandidate;
use App\Services\AI\GeminiRetrievalSourceScope;
use App\Services\AI\GeminiRetrievalSourceTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Tools\FileSearch;
use RuntimeException;
use Tests\TestCase;

/**
 * Batasan sumber retrieval Gemini File Search sebelum generasi jawaban.
 *
 * Tool yang diizinkan hanya tool ber-scope kandidat eligible; buktinya adalah
 * payload permintaan yang diserialisasi lewat provider koneksi (Http::fake),
 * bukan sekadar keluaran helper internal. Tidak ada panggilan jaringan nyata.
 */
class GeminiRetrievalSourceScopeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const STORE = 'fileSearchStores/store-prime-dev';

    private const CREDENTIAL = 'fictional-prime-gemini-key';

    private ?AiProviderConfigurationRevision $activeRevision = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['gemini_file_search.indexing.store' => 'store-prime-dev']);
        // Resolver DNS PRIME diganti agar suite tidak menyentuh jaringan nyata.
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
    }

    public function test_scope_of_current_revision_serializes_exact_quoted_filter_and_excludes_other_revisions(): void
    {
        $this->activateGeminiRevision();

        $historical = $this->sop(['document_code' => 'SOP-UPCAST-001', 'version' => '2.0']);
        $this->completedIndex($historical);
        KnowledgeDocument::query()->whereKey($historical->id)->update(['is_published' => false]);

        $current = $this->sop(['document_code' => 'SOP-UPCAST-001', 'version' => '3.0', 'is_published' => true]);
        $currentIndex = $this->completedIndex($current, ['google_document_name' => self::STORE.'/documents/current-doc']);

        $draft = $this->sop(['document_code' => 'SOP-UPCAST-001', 'version' => '4.0']);
        $this->completedIndex($draft, ['google_document_name' => self::STORE.'/documents/draft-doc']);

        $tool = app(GeminiRetrievalSourceScope::class)->tool();

        $this->assertInstanceOf(GeminiRetrievalSourceTool::class, $tool);
        $this->assertSame([self::STORE], $tool->ids());
        $this->assertSerializedTool($tool, sprintf(
            '(document_code="SOP-UPCAST-001" AND import_identity="%s")',
            $currentIndex->import_identity,
        ));
    }

    public function test_numeric_looking_versions_never_enter_the_filter_and_identities_pair_exactly(): void
    {
        $this->activateGeminiRevision();

        $expected = [];

        foreach (['SOP-NUM-1' => '3.0', 'SOP-NUM-2' => '3.1', 'SOP-NUM-3' => '3.10'] as $code => $version) {
            $document = $this->sop(['document_code' => $code, 'version' => $version, 'is_published' => true]);
            $index = $this->completedIndex($document);
            $expected[] = sprintf('(document_code="%s" AND import_identity="%s")', $code, $index->import_identity);
        }

        $tool = app(GeminiRetrievalSourceScope::class)->tool();

        $this->assertInstanceOf(GeminiRetrievalSourceTool::class, $tool);
        $this->assertSerializedTool($tool, implode(' OR ', $expected));
    }

    public function test_cross_store_record_never_enters_the_request(): void
    {
        $this->activateGeminiRevision();

        $valid = $this->sop(['document_code' => 'SOP-STORE-1', 'is_published' => true]);
        $validIndex = $this->completedIndex($valid);

        $otherStore = $this->sop(['document_code' => 'SOP-STORE-2', 'is_published' => true]);
        $this->completedIndex($otherStore, [
            'store_id' => 'fileSearchStores/store-other',
            'google_document_name' => 'fileSearchStores/store-other/documents/doc',
        ]);

        $tool = app(GeminiRetrievalSourceScope::class)->tool();

        $this->assertInstanceOf(GeminiRetrievalSourceTool::class, $tool);
        $this->assertSerializedTool($tool, sprintf(
            '(document_code="SOP-STORE-1" AND import_identity="%s")',
            $validIndex->import_identity,
        ));
    }

    public function test_source_tool_cannot_be_widened_to_another_store(): void
    {
        $this->activateGeminiRevision();

        $document = $this->sop(['document_code' => 'SOP-STORE-IMMUTABLE', 'is_published' => true]);
        $this->completedIndex($document);

        $tool = app(GeminiRetrievalSourceScope::class)->tool();

        $this->assertInstanceOf(GeminiRetrievalSourceTool::class, $tool);

        // Field $stores milik SDK publik; scope store tetap dari identitas terverifikasi.
        $tool->stores[] = 'fileSearchStores/store-other';

        $this->assertSame([self::STORE], $tool->ids());
        Http::assertNothingSent();
    }

    public function test_no_eligible_source_produces_no_scope_and_no_request(): void
    {
        $this->activateGeminiRevision();

        $unpublished = $this->sop(['document_code' => 'SOP-IDLE-1']);
        $this->completedIndex($unpublished);

        $this->assertNull(app(GeminiRetrievalSourceScope::class)->tool());
        Http::assertNothingSent();

        // Tanpa scope, satu-satunya alternatif adalah File Search tak terbatas;
        // provider koneksi menolaknya sehingga tidak ada generasi tanpa batasan.
        $provider = app(GeminiFileSearchConnection::class)->resolve();

        try {
            $provider->textGateway()->generateTextStep(
                $provider,
                $provider->defaultTextModel(),
                null,
                [new UserMessage('Bagaimana interval pemeriksaan mesin?')],
                [new FileSearch([self::STORE])],
                null,
                null,
                30,
                new StepContext,
            );
            $this->fail('Tidak boleh ada generasi dengan File Search tanpa scope eligible.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search requires an eligible source scope.', $exception->getMessage());
        }

        Http::assertNothingSent();

        // Tanpa revisi chat Gemini eligible, tidak ada sumber kredensial yang pasti.
        AiProviderConfigurationRevision::query()->update(['enabled' => false]);
        $published = $this->sop(['document_code' => 'SOP-IDLE-2', 'is_published' => true]);
        $this->completedIndex($published);

        $this->assertNull(app(GeminiRetrievalSourceScope::class)->tool());
        Http::assertNothingSent();
    }

    public function test_same_code_and_version_candidates_keep_distinct_import_identities(): void
    {
        $this->activateGeminiRevision();
        $tool = new GeminiRetrievalSourceTool([
            new GeminiRetrievalCandidate('SOP-DUP-1', '2.0', self::STORE, self::STORE.'/documents/doc-1', 'g-import-a1', 1, 1),
            new GeminiRetrievalCandidate('SOP-DUP-1', '2.0', self::STORE, self::STORE.'/documents/doc-2', 'g-import-b2', 1, 1),
        ]);

        $this->assertSerializedTool($tool,
            '(document_code="SOP-DUP-1" AND import_identity="g-import-a1")'
            .' OR (document_code="SOP-DUP-1" AND import_identity="g-import-b2")');
    }

    public function test_non_canonical_metadata_identity_fails_closed(): void
    {
        $this->activateGeminiRevision();

        $forged = $this->sop(['document_code' => 'SOP-FORGE-1', 'version' => '3.0" OR document_code="X', 'is_published' => true]);
        $this->completedIndex($forged);

        try {
            app(GeminiRetrievalSourceScope::class)->tool();
            $this->fail('Identitas metadata tidak kanonik harus gagal tertutup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini retrieval source scope cannot be established.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_blank_import_identity_produces_no_scope_and_no_request(): void
    {
        $this->activateGeminiRevision();

        $document = $this->sop(['document_code' => 'SOP-BLANK-1', 'is_published' => true]);
        $this->completedIndex($document, ['import_identity' => '']);

        $this->assertNull(app(GeminiRetrievalSourceScope::class)->tool());
        Http::assertNothingSent();
    }

    public function test_credential_provenance_mismatch_produces_no_scope_and_no_request(): void
    {
        $this->activateGeminiRevision();

        $document = $this->sop(['document_code' => 'SOP-PROV-1', 'is_published' => true]);
        $this->completedIndex($document, [
            'credential_configuration_id' => 999999,
            'credential_revision_id' => 999999,
        ]);

        $this->assertNull(app(GeminiRetrievalSourceScope::class)->tool());
        Http::assertNothingSent();
    }

    public function test_forged_import_identity_fails_closed_before_any_request(): void
    {
        $this->activateGeminiRevision();

        $forged = $this->sop(['document_code' => 'SOP-FORGE-2', 'is_published' => true]);
        $this->completedIndex($forged, ['import_identity' => 'g-import" OR document_code="X']);

        try {
            app(GeminiRetrievalSourceScope::class)->tool();
            $this->fail('Import identity tidak kanonik harus gagal tertutup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini retrieval source scope cannot be established.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_source_tool_refuses_blank_or_forged_import_identity(): void
    {
        foreach (['', 'g-import" OR document_code="X'] as $importIdentity) {
            try {
                new GeminiRetrievalSourceTool([
                    new GeminiRetrievalCandidate('SOP-DIRECT-1', '3.0', self::STORE, self::STORE.'/documents/doc-1', $importIdentity, 1, 1),
                ]);
                $this->fail('Kandidat dengan import identity tidak sah harus ditolak tool.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Import identity must be a non-empty canonical string.', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_import_metadata_contract_still_carries_version_and_import_identity(): void
    {
        $metadata = new GeminiDocumentMetadata('SOP-CONTRACT-1', '3.0', 'g-import-contract');

        $this->assertSame([
            'document_code' => 'SOP-CONTRACT-1',
            'version' => '3.0',
            'import_identity' => 'g-import-contract',
        ], $metadata->toImportMetadata());
    }

    public function test_unrestricted_file_search_tool_is_refused_before_any_request(): void
    {
        $this->activateGeminiRevision();
        $document = $this->sop(['document_code' => 'SOP-GUARD-1', 'is_published' => true]);
        $this->completedIndex($document);

        $provider = app(GeminiFileSearchConnection::class)->resolve();

        try {
            $provider->textGateway()->generateTextStep(
                $provider,
                $provider->defaultTextModel(),
                null,
                [new UserMessage('Bagaimana interval pemeriksaan mesin?')],
                [new FileSearch([self::STORE], fn ($query) => $query->where('document_code', 'SOP-GUARD-1'))],
                null,
                null,
                30,
                new StepContext,
            );
            $this->fail('File Search tanpa scope sumber eligible harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search requires an eligible source scope.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * Buktikan perilaku tool pada payload permintaan nyata (diserialisasi SDK),
     * bukan keluaran helper; permintaan tetap di-hambat Http::fake.
     */
    private function assertSerializedTool(GeminiRetrievalSourceTool $tool, string $expectedFilter): void
    {
        Http::fake([self::BASE_URL.'/interactions' => Http::response([
            'status' => 'completed',
            'steps' => [
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Jawaban ber-scope.']]],
            ],
        ])]);

        $provider = app(GeminiFileSearchConnection::class)->resolve();

        $provider->textGateway()->generateTextStep(
            $provider,
            $provider->defaultTextModel(),
            'Jawab hanya dari SOP terbit PRIME.',
            [new UserMessage('Bagaimana interval pemeriksaan mesin?')],
            [$tool],
            null,
            null,
            30,
            new StepContext,
        );

        Http::assertSent(function (Request $request) use ($expectedFilter): bool {
            $tools = $request['tools'];

            return $request->url() === self::BASE_URL.'/interactions'
                && $request->header('x-goog-api-key') === [self::CREDENTIAL]
                && is_array($tools)
                && count($tools) === 1
                && $tools[0]['type'] === 'file_search'
                && $tools[0]['file_search_store_names'] === [self::STORE]
                && $tools[0]['metadata_filter'] === $expectedFilter;
        });
    }

    private function sop(array $overrides = []): KnowledgeDocument
    {
        return KnowledgeDocument::factory()->create($overrides);
    }

    private function completedIndex(KnowledgeDocument $document, array $overrides = []): KnowledgeDocumentGeminiIndex
    {
        $revision = $this->activeRevision;

        return KnowledgeDocumentGeminiIndex::create(array_merge([
            'knowledge_document_id' => $document->id,
            'document_code' => $document->document_code,
            'document_version' => $document->version,
            'document_checksum' => $document->checksum,
            'store_id' => self::STORE,
            'google_file_id' => 'files/'.Str::lower(Str::random(10)),
            'google_document_name' => self::STORE.'/documents/'.Str::lower(Str::random(10)),
            'state' => 'completed',
            'generation' => 1,
            'credential_configuration_id' => $revision?->configuration_id,
            'credential_revision_id' => $revision?->id,
            'import_identity' => 'import-'.Str::lower(Str::random(20)),
        ], $overrides));
    }

    private function activateGeminiRevision(): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Fictional Chat',
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
        $this->activeRevision = $revision;

        return $revision;
    }
}
