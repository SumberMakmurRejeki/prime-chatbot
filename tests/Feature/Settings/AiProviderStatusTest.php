<?php

namespace Tests\Feature\Settings;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\GeminiDocumentIndexState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kontrak status provider AI: konfigurasi chatbot, kesiapan store File Search,
 * dan agregat indeksasi ditampilkan terpisah tanpa saling menyiratkan.
 */
class AiProviderStatusTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS_URL = '/settings/ai/providers/status';

    private const VERIFY_URL = self::STATUS_URL;

    private const ACTIVE_CREDENTIAL = 'fictional-prime-gemini-key';

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const STORE_URL = self::BASE_URL.'/fileSearchStores/store-1';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->manager = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        config(['gemini_file_search.indexing.store' => 'store-1']);
    }

    public function test_only_active_ai_manager_can_read_status_or_verify_store_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => false]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'manage_ai' => false]);
        Http::preventStrayRequests();

        $this->get(self::STATUS_URL)->assertRedirect('/login');
        $this->post(self::VERIFY_URL)->assertRedirect('/login');
        $this->actingAs($admin)->get(self::STATUS_URL)->assertForbidden();
        $this->actingAs($admin)->post(self::VERIFY_URL)->assertForbidden();
        $this->actingAs($operator)->get(self::STATUS_URL)->assertRedirect('/403');
        $this->actingAs($operator)->post(self::VERIFY_URL)->assertRedirect('/403');

        Http::assertNothingSent();
        $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();
    }

    public function test_chat_configuration_that_never_passed_a_connection_test_is_not_reported_as_configured(): void
    {
        $configuration = $this->configuration('Chat Untested', 'chat');
        $revision = $this->revision($configuration, 1);
        $revision->forceFill(['test_state' => 'untested', 'test_attempt_id' => null, 'tested_at' => null])->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('chat.state', 'not_configured');
    }

    public function test_chat_configuration_is_reported_as_configured_without_claiming_live_health(): void
    {
        $this->activeChatRevision();
        Http::preventStrayRequests();

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        $response->assertJsonPath('chat.state', 'configured')
            ->assertJsonPath('chat.driver', 'gemini')
            ->assertJsonPath('chat.model_id', 'gemini-3.8-flash');
        $this->assertStringContainsString('belum diperiksa', (string) $response->json('chat.message'));
        Http::assertNothingSent();
    }

    public function test_chat_configuration_that_is_missing_or_not_active_is_not_configured(): void
    {
        $embedding = $this->configuration('Embedding Only', 'embedding');
        $revision = $this->revision($embedding, 1, ['driver' => 'gemini', 'model_id' => 'gemini-embedding-001']);
        $embedding->forceFill(['active_revision_id' => $revision->id])->save();

        $unactivated = $this->configuration('Chat Not Activated', 'chat');
        $this->revision($unactivated, 1);

        $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('chat.state', 'not_configured')
            ->assertJsonPath('chat.model_id', null);
    }

    public function test_chat_configuration_with_unsupported_driver_is_reported_separately(): void
    {
        $configuration = $this->configuration('Chat Legacy Vendor', 'chat');
        $revision = $this->revision($configuration, 1, [
            'driver' => 'legacy-vendor',
            'model_id' => 'legacy-chat-1',
        ]);
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('chat.state', 'unsupported')
            ->assertJsonPath('chat.driver', 'legacy-vendor')
            ->assertJsonPath('chat.model_id', 'legacy-chat-1');
    }

    public function test_ambiguous_chat_configuration_is_reported_as_unknown(): void
    {
        $this->activeChatRevision();
        $this->activeChatRevision('Second Chat');

        $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('chat.state', 'unknown');
    }

    public function test_file_search_identity_is_canonical_for_snapshot_and_live_verification(): void
    {
        $this->activeChatRevision();
        Http::preventStrayRequests();

        foreach (['store-1', 'fileSearchStores/store-1'] as $configured) {
            config(['gemini_file_search.indexing.store' => $configured]);

            $this->actingAs($this->manager)->get(self::STATUS_URL)
                ->assertOk()
                ->assertJsonPath('file_search.state', 'configured')
                ->assertJsonPath('file_search.store', 'fileSearchStores/store-1');
        }

        Http::assertNothingSent();

        Http::fake([self::STORE_URL => Http::sequence()
            ->push(['name' => 'fileSearchStores/store-1'])
            ->push(['name' => 'fileSearchStores/store-1'])]);

        foreach (['store-1', 'fileSearchStores/store-1'] as $configured) {
            config(['gemini_file_search.indexing.store' => $configured]);

            $this->actingAs($this->manager)->post(self::VERIFY_URL)
                ->assertOk()
                ->assertJsonPath('file_search_verification.state', 'accessible')
                ->assertJsonPath('file_search_verification.store', 'fileSearchStores/store-1');
        }

        Http::assertNotSent(fn (Request $request): bool => $request->url() !== self::STORE_URL);
        Http::assertSentCount(2);
    }

    public function test_file_search_configuration_state_never_contacts_google(): void
    {
        $this->activeChatRevision();
        Http::preventStrayRequests();

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        $response->assertJsonPath('file_search.state', 'configured')
            ->assertJsonPath('file_search.store', 'fileSearchStores/store-1')
            ->assertJsonPath('file_search.indexing_enabled', false);
        $this->assertStringContainsString('belum diperiksa', (string) $response->json('file_search.message'));
        Http::assertNothingSent();
    }

    public function test_file_search_configuration_state_reports_missing_store_without_network_access(): void
    {
        foreach (['', '   ', 'fileSearchStores/', 'store-1/../../x'] as $configured) {
            config(['gemini_file_search.indexing.store' => $configured]);
            Http::preventStrayRequests();

            $this->actingAs($this->manager)->get(self::STATUS_URL)
                ->assertOk()
                ->assertJsonPath('file_search.state', 'not_configured')
                ->assertJsonPath('file_search.store', null);
        }

        Http::assertNothingSent();
    }

    public function test_indexing_aggregate_distinguishes_no_documents_from_not_scheduled_records(): void
    {
        $this->activeChatRevision();

        $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'disabled')
            ->assertJsonPath('gemini_indexing.counts.not_recorded', 0);

        config(['gemini_file_search.indexing.enabled' => true]);

        $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'no_documents')
            ->assertJsonPath('knowledge_documents.total', 0);

        $this->document();

        $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'not_started')
            ->assertJsonPath('gemini_indexing.counts.not_recorded', 1);
    }

    public function test_indexing_aggregate_reports_in_progress_failed_and_unknown_states_separately(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);

        $queued = $this->indexRow($this->document(), GeminiDocumentIndexState::Queued);
        $this->indexRow($this->document(), GeminiDocumentIndexState::Failed);

        $inProgressResponse = $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'in_progress')
            ->assertJsonPath('gemini_indexing.counts.in_progress', 1)
            ->assertJsonPath('gemini_indexing.counts.failed', 1);

        // Baris hasil tak diketahui harus terlihat sebagai belum pasti, bukan
        // dilaporkan sebagai kegagalan pasti.
        $queued->forceFill(['state' => GeminiDocumentIndexState::Unknown])->save();

        $unknownResponse = $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'unknown')
            ->assertJsonPath('gemini_indexing.counts.unknown', 1)
            ->assertJsonPath('gemini_indexing.counts.failed', 1);

        $queued->forceFill(['state' => GeminiDocumentIndexState::Failed])->save();

        $failedResponse = $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'failed')
            ->assertJsonPath('gemini_indexing.counts.failed', 2);

        foreach ([
            [$inProgressResponse, 'masih dalam proses indeksasi'],
            [$unknownResponse, 'pemeriksaan manual'],
            [$failedResponse, 'gagal diindeks'],
        ] as [$response, $limitation]) {
            $this->assertStringContainsString(
                $limitation,
                (string) implode(' ', (array) $response->json('limitations')),
            );
        }
    }

    public function test_completed_subset_is_reported_as_partial_when_some_documents_are_not_scheduled(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);

        $this->indexRow($this->document(), GeminiDocumentIndexState::Completed);
        $this->document();

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        $response->assertJsonPath('gemini_indexing.state', 'partial')
            ->assertJsonPath('gemini_indexing.counts.completed', 1)
            ->assertJsonPath('gemini_indexing.counts.not_recorded', 1);
        $this->assertStringContainsString('belum dijadwalkan', (string) implode(' ', (array) $response->json('limitations')));
    }

    public function test_completed_indexing_does_not_imply_publication_or_retrieval_readiness(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);

        $document = $this->document();
        $this->indexRow($document, GeminiDocumentIndexState::Completed);

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        $response->assertJsonPath('gemini_indexing.state', 'completed')
            ->assertJsonPath('gemini_indexing.counts.completed', 1)
            ->assertJsonPath('knowledge_documents.total', 1)
            ->assertJsonPath('knowledge_documents.published', 0)
            ->assertJsonPath('knowledge_documents.unpublished', 1);
        $this->assertStringContainsString('publikasi', (string) $response->json('gemini_indexing.message'));
        $this->assertStringContainsString('publikasikan', (string) implode(' ', (array) $response->json('limitations')));
    }

    public function test_persisted_indexing_records_targeting_other_store_are_reported(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);

        $this->indexRow($this->document(), GeminiDocumentIndexState::Completed, 'fileSearchStores/store-old');

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        $response->assertJsonPath('gemini_indexing.store_scope.records_with_configured_store', 0)
            ->assertJsonPath('gemini_indexing.store_scope.records_with_other_store', 1);
        $this->assertStringContainsString('store berbeda', (string) implode(' ', (array) $response->json('limitations')));
    }

    public function test_configured_store_does_not_inherit_completed_counts_from_records_of_another_store(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);
        Http::preventStrayRequests();

        $historicalDocuments = array_map(fn (): KnowledgeDocument => $this->document(), range(1, 10));

        foreach ($historicalDocuments as $document) {
            $this->indexRow($document, GeminiDocumentIndexState::Completed, 'fileSearchStores/store-old');
        }

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        // Keberhasilan indeksasi di store lama tidak pernah menjadi bukti
        // untuk store yang sedang dikonfigurasi sekarang.
        $response->assertJsonPath('gemini_indexing.state', 'not_started')
            ->assertJsonPath('gemini_indexing.counts.completed', 0)
            ->assertJsonPath('gemini_indexing.counts.not_recorded', 10)
            ->assertJsonPath('gemini_indexing.store_scope.records_with_configured_store', 0)
            ->assertJsonPath('gemini_indexing.store_scope.records_with_other_store', 10);
        $this->assertStringNotContainsString('selesai diindeks', (string) $response->json('gemini_indexing.message'));
        Http::assertNothingSent();

        $this->assertDatabaseCount('knowledge_document_gemini_indexings', 10);
        $this->assertDatabaseHas('knowledge_document_gemini_indexings', [
            'store_id' => 'fileSearchStores/store-old',
            'state' => GeminiDocumentIndexState::Completed->value,
        ]);
    }

    public function test_failed_and_unknown_records_of_another_store_do_not_drive_the_aggregate_state(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);
        Http::preventStrayRequests();

        $this->indexRow($this->document(), GeminiDocumentIndexState::Failed, 'fileSearchStores/store-old');
        $this->indexRow($this->document(), GeminiDocumentIndexState::Unknown, 'fileSearchStores/store-old');

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        $response->assertJsonPath('gemini_indexing.state', 'not_started')
            ->assertJsonPath('gemini_indexing.counts.failed', 0)
            ->assertJsonPath('gemini_indexing.counts.unknown', 0)
            ->assertJsonPath('gemini_indexing.store_scope.records_with_other_store', 2);
        $this->assertStringContainsString('store berbeda', (string) implode(' ', (array) $response->json('limitations')));
    }

    public function test_counts_and_state_follow_only_records_of_the_configured_store(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);
        Http::preventStrayRequests();

        $this->indexRow($this->document(), GeminiDocumentIndexState::Failed, 'fileSearchStores/store-old');
        $this->indexRow($this->document(), GeminiDocumentIndexState::Completed);

        // Kegagalan store lama tidak boleh mewarnai store aktif; dokumen yang
        // hanya terindeks ke store lama tetap terhitung belum tercatat.
        $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'partial')
            ->assertJsonPath('gemini_indexing.counts.failed', 0)
            ->assertJsonPath('gemini_indexing.counts.completed', 1)
            ->assertJsonPath('gemini_indexing.counts.not_recorded', 1);

        $queued = $this->indexRow($this->document(), GeminiDocumentIndexState::Queued);

        $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'in_progress')
            ->assertJsonPath('gemini_indexing.counts.in_progress', 1);

        $queued->forceFill(['state' => GeminiDocumentIndexState::Unknown])->save();

        $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk()
            ->assertJsonPath('gemini_indexing.state', 'unknown')
            ->assertJsonPath('gemini_indexing.counts.unknown', 1)
            ->assertJsonPath('gemini_indexing.counts.failed', 0);
    }

    public function test_records_without_store_identity_are_never_counted_for_the_configured_store(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);
        Http::preventStrayRequests();

        $this->indexRow($this->document(), GeminiDocumentIndexState::Completed, null);

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        $response->assertJsonPath('gemini_indexing.state', 'not_started')
            ->assertJsonPath('gemini_indexing.counts.completed', 0)
            ->assertJsonPath('gemini_indexing.counts.not_recorded', 1)
            ->assertJsonPath('gemini_indexing.store_scope.records_without_store', 1)
            ->assertJsonPath('gemini_indexing.store_scope.records_with_configured_store', 0)
            ->assertJsonPath('gemini_indexing.store_scope.records_with_other_store', 0);
    }

    public function test_missing_configured_store_never_claims_completion_from_historical_records(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);
        config(['gemini_file_search.indexing.store' => '']);
        Http::preventStrayRequests();

        $this->indexRow($this->document(), GeminiDocumentIndexState::Completed, 'fileSearchStores/store-old');

        $response = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();

        $response->assertJsonPath('file_search.state', 'not_configured')
            ->assertJsonPath('file_search.store', null)
            ->assertJsonPath('gemini_indexing.state', 'not_started')
            ->assertJsonPath('gemini_indexing.counts.completed', 0)
            ->assertJsonPath('gemini_indexing.counts.not_recorded', 1)
            ->assertJsonPath('gemini_indexing.store_scope.records_with_other_store', 1);
    }

    public function test_both_accepted_store_name_forms_aggregate_the_same_current_store_records(): void
    {
        $this->activeChatRevision();
        config(['gemini_file_search.indexing.enabled' => true]);
        Http::preventStrayRequests();

        $this->indexRow($this->document(), GeminiDocumentIndexState::Completed, 'fileSearchStores/store-1');
        $this->indexRow($this->document(), GeminiDocumentIndexState::Failed, 'fileSearchStores/store-1');

        foreach (['store-1', 'fileSearchStores/store-1'] as $configured) {
            config(['gemini_file_search.indexing.store' => $configured]);

            $this->actingAs($this->manager)->get(self::STATUS_URL)
                ->assertOk()
                ->assertJsonPath('gemini_indexing.state', 'failed')
                ->assertJsonPath('gemini_indexing.counts.completed', 1)
                ->assertJsonPath('gemini_indexing.counts.failed', 1)
                ->assertJsonPath('gemini_indexing.counts.not_recorded', 0);
        }

        Http::assertNothingSent();
    }

    public function test_explicit_store_verification_reports_accessible_store_read_only(): void
    {
        $revision = $this->activeChatRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['name' => 'fileSearchStores/store-1'])]);

        $response = $this->actingAs($this->manager)->post(self::VERIFY_URL)->assertOk();

        $response->assertJsonPath('file_search_verification.state', 'accessible')
            ->assertJsonPath('file_search_verification.store', 'fileSearchStores/store-1')
            ->assertJsonPath('file_search_verification.reason_code', 'store_accessible');

        // Verifikasi live tidak pernah mengubah status baca biasa: snapshot
        // tetap hanya menyatakan konfigurasi, tanpa menghubungi Google lagi.
        $this->actingAs($this->manager)->get(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('file_search.state', 'configured')
            ->assertJsonMissingPath('file_search_verification');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::STORE_URL);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        Http::assertSentCount(1);
        $this->assertSame($revision->id, $revision->configuration->fresh()->active_revision_id);
        $this->assertSame('passed', $revision->fresh()->test_state);
        $this->assertDatabaseCount('knowledge_document_gemini_indexings', 0);
    }

    public function test_store_verification_maps_denied_missing_unavailable_and_invalid_responses(): void
    {
        $this->activeChatRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::sequence()
            ->push(['error' => ['code' => 403, 'message' => 'Permission denied '.self::ACTIVE_CREDENTIAL]], 403)
            ->push(['error' => ['code' => 404]], 404)
            ->push(['error' => ['code' => 503]], 503)
            ->push('not-json', 200, ['Content-Type' => 'application/json'])]);

        $expectedMessages = [
            'access_denied' => 'ditolak',
            'missing_store' => 'tidak ditemukan',
            'unavailable' => 'tidak dapat dijangkau',
            'invalid_response' => 'tidak sesuai kontrak',
        ];

        foreach ($expectedMessages as $state => $messageFragment) {
            $response = $this->actingAs($this->manager)->post(self::VERIFY_URL)->assertOk();

            $response->assertJsonPath('file_search_verification.state', $state);
            $this->assertStringContainsString($messageFragment, (string) $response->json('file_search_verification.message'));
            $this->assertStringNotContainsString(self::ACTIVE_CREDENTIAL, $response->getContent());
        }

        Http::assertSentCount(4);
    }

    public function test_store_verification_without_configured_store_never_contacts_google(): void
    {
        config(['gemini_file_search.indexing.store' => '']);
        Http::preventStrayRequests();

        $this->actingAs($this->manager)->post(self::VERIFY_URL)
            ->assertOk()
            ->assertJsonPath('file_search_verification.state', 'not_configured')
            ->assertJsonPath('file_search_verification.store', null);

        Http::assertNothingSent();
    }

    public function test_status_responses_do_not_disclose_stored_credentials(): void
    {
        $this->activeChatRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['name' => 'fileSearchStores/store-1'])]);

        $status = $this->actingAs($this->manager)->get(self::STATUS_URL)->assertOk();
        $verification = $this->actingAs($this->manager)->post(self::VERIFY_URL)->assertOk();

        foreach ([$status, $verification] as $response) {
            $payload = $response->getContent();
            $this->assertStringNotContainsString(self::ACTIVE_CREDENTIAL, $payload);
            $this->assertStringNotContainsString('credential', $payload);
            $this->assertStringNotContainsString('api_key', $payload);
            $this->assertStringNotContainsString('x-goog-api-key', $payload);
        }
    }

    private function activeChatRevision(string $displayName = 'Fictional Gemini'): AiProviderConfigurationRevision
    {
        $configuration = $this->configuration($displayName, 'chat');
        $revision = $this->revision($configuration, 1);
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function configuration(string $displayName, string $purpose): AiProviderConfiguration
    {
        return AiProviderConfiguration::query()->create([
            'display_name' => $displayName,
            'purpose' => $purpose,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function revision(AiProviderConfiguration $configuration, int $revisionNumber, array $overrides = []): AiProviderConfigurationRevision
    {
        $revision = $configuration->revisions()->make(array_replace([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.8-flash',
            'credential' => self::ACTIVE_CREDENTIAL,
            'enabled' => true,
        ], $overrides))->forceFill([
            'revision_number' => $revisionNumber,
            'test_state' => 'passed',
            'test_attempt_id' => 'fictional-attempt-id-'.$revisionNumber,
            'test_started_at' => now(),
            'tested_at' => now(),
        ]);
        $revision->save();

        return $revision;
    }

    private function document(): KnowledgeDocument
    {
        return KnowledgeDocument::factory()->create(['is_published' => false]);
    }

    private function indexRow(
        KnowledgeDocument $document,
        GeminiDocumentIndexState $state,
        ?string $storeId = 'fileSearchStores/store-1',
    ): KnowledgeDocumentGeminiIndex {
        return KnowledgeDocumentGeminiIndex::query()->create([
            'knowledge_document_id' => $document->id,
            'document_code' => $document->document_code,
            'document_version' => $document->version,
            'document_checksum' => $document->checksum,
            'store_id' => $storeId,
            'state' => $state,
            'generation' => 1,
        ]);
    }
}
