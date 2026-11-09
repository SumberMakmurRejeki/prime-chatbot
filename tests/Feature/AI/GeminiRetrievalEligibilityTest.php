<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use App\Models\User;
use App\Services\AI\GeminiRetrievalCandidate;
use App\Services\AI\GeminiRetrievalEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kebijakan kelayakan sumber retrieval Gemini File Search.
 *
 * Seluruh keputusan hanya bergantung pada keadaan MySQL dan konfigurasi
 * Laravel; tidak ada panggilan jaringan selama evaluasi, dibuktikan dengan
 * Http::preventStrayRequests() dan pemeriksaan assertNothingSent().
 */
class GeminiRetrievalEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 'fileSearchStores/store-prime-dev';

    private const CREDENTIAL = 'fictional-prime-gemini-key';

    private ?AiProviderConfigurationRevision $geminiRevision = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['gemini_file_search.indexing.store' => 'store-prime-dev']);
    }

    public function test_only_current_published_revision_is_eligible(): void
    {
        $this->activateGeminiRevision();
        $historical = $this->sop(['document_code' => 'SOP-UPCAST-001', 'version' => '2.0']);
        $this->completedIndex($historical);
        $current = $this->sop(['document_code' => 'SOP-UPCAST-001', 'version' => '3.0', 'is_published' => true]);
        $this->completedIndex($current, ['google_document_name' => self::STORE.'/documents/current-doc']);

        $this->assertSame([['SOP-UPCAST-001', '3.0']], $this->candidatePairs()->all());

        $candidate = app(GeminiRetrievalEligibilityService::class)->eligibleCandidates()->sole();
        $this->assertInstanceOf(GeminiRetrievalCandidate::class, $candidate);
        $this->assertSame(self::STORE, $candidate->storeName);
        $this->assertSame(self::STORE.'/documents/current-doc', $candidate->googleDocumentName);
        Http::assertNothingSent();
    }

    public function test_indexed_but_unpublished_revision_is_not_eligible(): void
    {
        $this->activateGeminiRevision();
        $current = $this->sop(['document_code' => 'SOP-UPCAST-001', 'version' => '3.0', 'is_published' => true]);
        $this->completedIndex($current);
        $draft = $this->sop(['document_code' => 'SOP-UPCAST-001', 'version' => '4.0']);
        $this->completedIndex($draft, ['google_document_name' => self::STORE.'/documents/draft-doc']);

        $this->assertSame([['SOP-UPCAST-001', '3.0']], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_published_revision_with_unfinished_or_failed_indexing_is_not_eligible(): void
    {
        $this->activateGeminiRevision();

        foreach (['queued', 'uploading', 'uploaded', 'importing', 'pending', 'failed', 'unknown'] as $state) {
            $document = $this->sop(['document_code' => 'SOP-STATE-'.$state, 'is_published' => true]);
            $this->completedIndex($document, ['state' => $state]);
        }

        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_indexing_done_for_a_historical_store_is_not_eligible_under_current_store(): void
    {
        $this->activateGeminiRevision();
        $document = $this->sop(['document_code' => 'SOP-STORE-001', 'is_published' => true]);
        $this->completedIndex($document, [
            'store_id' => 'fileSearchStores/store-old',
            'google_document_name' => 'fileSearchStores/store-old/documents/historic-doc',
        ]);

        $this->assertSame([], $this->candidatePairs()->all());

        config(['gemini_file_search.indexing.store' => 'store-old']);

        $this->assertSame([['SOP-STORE-001', '1.0']], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_snapshot_identity_mismatch_fails_closed(): void
    {
        $this->activateGeminiRevision();
        $staleVersion = $this->sop(['document_code' => 'SOP-STALE-1', 'is_published' => true]);
        $this->completedIndex($staleVersion);
        KnowledgeDocument::query()->whereKey($staleVersion->id)->update(['version' => '3.1']);

        $staleContent = $this->sop(['document_code' => 'SOP-STALE-2', 'is_published' => true]);
        $this->completedIndex($staleContent);
        KnowledgeDocument::query()->whereKey($staleContent->id)->update(['checksum' => hash('sha256', 'replaced')]);

        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_missing_or_ambiguous_google_identity_fails_closed(): void
    {
        $this->activateGeminiRevision();
        $unnamed = $this->sop(['document_code' => 'SOP-AMBIG-1', 'is_published' => true]);
        $this->completedIndex($unnamed, ['google_document_name' => null]);

        $crossStore = $this->sop(['document_code' => 'SOP-AMBIG-2', 'is_published' => true]);
        $this->completedIndex($crossStore, ['google_document_name' => 'fileSearchStores/store-other/documents/doc']);

        $unscoped = $this->sop(['document_code' => 'SOP-AMBIG-3', 'is_published' => true]);
        $this->completedIndex($unscoped, ['store_id' => null]);

        $this->sop(['document_code' => 'SOP-AMBIG-4', 'is_published' => true]);

        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_independent_codes_are_eligible_independently(): void
    {
        $this->activateGeminiRevision();
        $first = $this->sop(['document_code' => 'SOP-CODE-1', 'is_published' => true]);
        $this->completedIndex($first);
        $second = $this->sop(['document_code' => 'SOP-CODE-2', 'is_published' => true]);
        $this->completedIndex($second);

        $this->assertSame([['SOP-CODE-1', '1.0'], ['SOP-CODE-2', '1.0']], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_eligibility_follows_superseding_of_the_published_revision(): void
    {
        $this->activateGeminiRevision();
        $current = $this->sop(['document_code' => 'SOP-SUPER-1', 'version' => '3.0', 'is_published' => true]);
        $this->completedIndex($current);
        $successor = $this->sop(['document_code' => 'SOP-SUPER-1', 'version' => '4.0']);
        $this->completedIndex($successor, ['google_document_name' => self::STORE.'/documents/successor-doc']);

        $this->assertSame([['SOP-SUPER-1', '3.0']], $this->candidatePairs()->all());

        // Mengikuti invarian supersede KnowledgeDocumentService::publish(): hanya
        // flag is_published pemenang yang berpindah; baris historis tetap utuh.
        KnowledgeDocument::query()
            ->where('document_code', 'SOP-SUPER-1')
            ->whereKeyNot($successor->id)
            ->update(['is_published' => false]);
        KnowledgeDocument::query()->whereKey($successor->id)->update(['is_published' => true]);

        $this->assertSame([['SOP-SUPER-1', '4.0']], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_evaluation_never_mutates_registry_or_index_rows(): void
    {
        $this->activateGeminiRevision();
        $historical = $this->sop([
            'document_code' => 'SOP-HISTORY-1',
            'version' => '2.0',
            'published_at' => now()->subDays(2),
            'published_by' => User::factory(),
        ]);
        $this->completedIndex($historical);
        $current = $this->sop(['document_code' => 'SOP-HISTORY-1', 'version' => '3.0', 'is_published' => true]);
        $this->completedIndex($current);

        $documentsBefore = $this->documentRows();
        $indexesBefore = $this->indexRows();

        app(GeminiRetrievalEligibilityService::class)->eligibleCandidates();

        $this->assertSame($documentsBefore, $this->documentRows());
        $this->assertSame($indexesBefore, $this->indexRows());
        Http::assertNothingSent();
    }

    public function test_unusable_provider_configuration_fails_closed_without_network(): void
    {
        $this->activateGeminiRevision();
        config(['gemini_file_search.indexing.store' => '']);
        $unscoped = $this->sop(['document_code' => 'SOP-CONF-1', 'is_published' => true]);
        $this->completedIndex($unscoped, ['store_id' => null]);
        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();

        config(['gemini_file_search.indexing.store' => 'store-prime-dev']);
        AiProviderConfigurationRevision::query()->update(['enabled' => false]);
        $configured = $this->sop(['document_code' => 'SOP-CONF-2', 'is_published' => true]);
        $this->completedIndex($configured);
        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();

        // Lebih dari satu revisi eligible berarti kredensial File Search tidak pasti.
        AiProviderConfigurationRevision::query()->update(['enabled' => true]);
        $this->activateRevision(['driver' => 'openai', 'model_id' => 'gpt-fictional']);
        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_index_without_credential_provenance_is_not_eligible(): void
    {
        $this->activateGeminiRevision();
        $legacy = $this->sop(['document_code' => 'SOP-LEGACY-1', 'is_published' => true]);
        $this->completedIndex($legacy, [
            'credential_configuration_id' => null,
            'credential_revision_id' => null,
        ]);

        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_index_without_import_identity_is_not_eligible(): void
    {
        $this->activateGeminiRevision();
        $legacy = $this->sop(['document_code' => 'SOP-LEGACY-2', 'is_published' => true]);
        $this->completedIndex($legacy, ['import_identity' => null]);

        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    public function test_credential_rotation_fails_closed_until_verified_again(): void
    {
        $original = $this->activateGeminiRevision();
        $document = $this->sop(['document_code' => 'SOP-ROTATE-1', 'is_published' => true]);
        $index = $this->completedIndex($document);

        // Rotasi key di slot konfigurasi yang sama: akses kredensial baru ke
        // store tidak dapat diverifikasi offline, jadi kandidat dihentikan.
        $this->rotateGeminiRevision($original, 'fictional-rotated-gemini-key');
        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();

        // Recovery tanpa reindex: store dan konfigurasi tidak berubah, sehingga
        // memulihkan revisi terverifikasi cukup untuk kembali sah.
        $original->configuration->forceFill(['active_revision_id' => $original->id])->save();

        $candidate = app(GeminiRetrievalEligibilityService::class)->eligibleCandidates()->sole();
        $this->assertSame($original->configuration_id, $candidate->credentialConfigurationId);
        $this->assertSame($original->id, $candidate->credentialRevisionId);
        $this->assertSame($index->import_identity, $candidate->importIdentity);
        Http::assertNothingSent();
    }

    public function test_credential_from_another_configuration_is_not_eligible(): void
    {
        $this->activateGeminiRevision();
        $document = $this->sop(['document_code' => 'SOP-REPLACE-1', 'is_published' => true]);
        $this->completedIndex($document);

        // Konfigurasi provider diganti slot: konfigurasi lama dimatikan dan
        // konfigurasi baru menjadi sumber kredensial aktif.
        AiProviderConfigurationRevision::query()->update(['enabled' => false]);
        $this->activateRevision();

        $this->assertSame([], $this->candidatePairs()->all());
        Http::assertNothingSent();
    }

    private function sop(array $overrides = []): KnowledgeDocument
    {
        return KnowledgeDocument::factory()->create($overrides);
    }

    private function completedIndex(KnowledgeDocument $document, array $overrides = []): KnowledgeDocumentGeminiIndex
    {
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
            'credential_configuration_id' => $this->geminiRevision?->configuration_id,
            'credential_revision_id' => $this->geminiRevision?->id,
            'import_identity' => 'import-'.Str::lower(Str::random(20)),
        ], $overrides));
    }

    /**
     * @return Collection<int, array{0: string, 1: string}>
     */
    private function candidatePairs(): Collection
    {
        return app(GeminiRetrievalEligibilityService::class)
            ->eligibleCandidates()
            ->map(fn (GeminiRetrievalCandidate $candidate): array => [$candidate->documentCode, $candidate->version]);
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: bool, 3: ?string, 4: ?int}>
     */
    private function documentRows(): array
    {
        return KnowledgeDocument::query()->orderBy('id')->get()->map(
            fn (KnowledgeDocument $document): array => [
                $document->id,
                $document->status,
                $document->is_published,
                $document->published_at?->toIso8601String(),
                $document->published_by,
            ],
        )->all();
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: ?string, 3: ?string}>
     */
    private function indexRows(): array
    {
        return KnowledgeDocumentGeminiIndex::query()->orderBy('id')->get()->map(
            fn (KnowledgeDocumentGeminiIndex $index): array => [
                $index->id,
                $index->state->value,
                $index->store_id,
                $index->google_document_name,
            ],
        )->all();
    }

    private function activateGeminiRevision(): AiProviderConfigurationRevision
    {
        $revision = $this->activateRevision(['driver' => 'gemini', 'model_id' => 'gemini-3.8-flash']);
        $this->geminiRevision = $revision;

        return $revision;
    }

    private function activateRevision(array $overrides = []): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Fictional Chat',
            'purpose' => 'chat',
        ]);
        $revision = $configuration->revisions()->make(array_merge([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.8-flash',
            'credential' => self::CREDENTIAL,
            'enabled' => true,
        ], $overrides))->forceFill([
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
            'test_attempt_id' => 'fictional-rotated-attempt-id',
            'test_started_at' => now(),
            'tested_at' => now(),
        ]);
        $revision->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $revision;
    }
}
