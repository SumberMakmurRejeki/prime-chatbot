<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\VectorStore\RetrievalQuery;
use App\Services\AI\VectorStore\VectorSearchResult;
use App\Services\AI\VectorStore\VectorSpace;
use App\Services\AI\VectorStore\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\Conversation;
use Tests\TestCase;

class AiAssistantChatTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->activateChatRevision();
        $this->activateEmbeddingRevision();
        $this->pinEndpoint();
        Http::preventStrayRequests();
    }

    public function test_question_is_answered_from_published_pdf_evidence_with_real_provenance(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"status":"answer","citations":[{"source_id":"S1","quote":"Evidence text 4"}]}']]],
            ]),
        ]);

        $conversationId = $this->createConversation();

        $requestId = (string) Str::uuid();
        $this->postJson("/assistant/conversations/{$conversationId}/messages", ['message' => 'Bagaimana kriteria inspeksi bearing?', 'request_id' => $requestId])
            ->assertOk()
            ->assertJson(['message' => 'Jawaban tersimpan.', 'request_id' => $requestId]);

        $messages = DB::table('agent_conversation_messages')->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('Evidence text 4 [S1]', $messages[1]->content);
        $citations = json_decode($messages[1]->meta, true)['citations'];
        $this->assertSame('DOC-7', $citations[0]['code']);
        $this->assertSame('Manual FNC-01', $citations[0]['title']);
        $this->assertSame('2.0', $citations[0]['version']);
        $this->assertSame('2-4', $citations[0]['page']);
        $this->assertSame('p2-4', $citations[0]['locator']);

        $this->get("/assistant/conversations/{$conversationId}")
            ->assertOk()
            ->assertSee('Evidence text 4 [S1]')
            ->assertSee('Manual FNC-01')
            ->assertSee('halaman 2-4');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/chat/completions'));
    }

    public function test_unrelated_question_without_matching_evidence_abstains_without_inventing_facts(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]])]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Siapa presiden Indonesia?', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $messages = DB::table('agent_conversation_messages')->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('belum dapat memastikan', $messages[1]->content);
        $this->assertSame('[]', json_encode(json_decode($messages[1]->meta, true)['citations']));
        Http::assertSentCount(1); // embedding saja, tidak ada generation call.
    }

    public function test_duplicate_retry_of_the_same_question_does_not_double_persist(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"status":"answer","citations":[{"source_id":"S1","quote":"Evidence text 4"}]}']]],
            ]),
        ]);

        $conversationId = $this->createConversation();
        $requestId = (string) Str::uuid();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan sama', 'request_id' => $requestId])->assertRedirect();
        $this->postJson("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan sama', 'request_id' => $requestId])
            ->assertOk()
            ->assertJson(['message' => 'Jawaban tersimpan.', 'request_id' => $requestId]);

        $this->assertSame(2, DB::table('agent_conversation_messages')->count());
        Http::assertSentCount(2); // embedding dan generation hanya dipanggil pada percobaan pertama.
    }

    public function test_provider_failure_fails_safely_with_actionable_error_and_no_partial_messages(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response(['error' => ['message' => 'upstream down']], 503),
        ]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect()
            ->assertSessionHas('flash_error', 'Assistant tidak dapat menjawab saat ini. Periksa konfigurasi Chat Provider dan layanan Knowledge Base, lalu coba lagi.');

        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
    }

    public function test_missing_chat_provider_fails_with_actionable_admin_guidance(): void
    {
        AiProviderConfiguration::query()->where('purpose', 'chat')->delete();

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan tanpa provider', 'request_id' => (string) Str::uuid()])
            ->assertRedirect()
            ->assertSessionHas('flash_error');

        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
        Http::assertNothingSent();
    }

    public function test_unrelated_nonempty_evidence_allows_safe_model_abstention(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"status":"abstain","citations":[]}']]],
            ]),
        ]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan tidak terkait', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $messages = DB::table('agent_conversation_messages')->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('belum dapat memastikan', $messages[1]->content);
        $this->assertSame('[]', json_encode(json_decode($messages[1]->meta, true)['citations']));
        Http::assertSentCount(2); // embedding + generation; abstain tetap sah.
    }

    public function test_fabricated_citation_quote_fails_closed_as_abstention(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"status":"answer","citations":[{"source_id":"S1","quote":"Kutipan yang tidak ada dalam bukti"}]}']]],
            ]),
        ]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $messages = DB::table('agent_conversation_messages')->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('belum dapat memastikan', $messages[1]->content);
        $this->assertSame('[]', json_encode(json_decode($messages[1]->meta, true)['citations']));
        $this->assertStringNotContainsString('Klaim', $messages[1]->content);
    }

    public function test_unsupported_model_prose_is_never_persisted_alongside_a_valid_quote(): void
    {
        $this->publishedDocument();
        $this->app->instance(VectorStore::class, new StaticRetrievalStore([$this->hit(0.93)]));
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"status":"answer","answer":"Bearing selalu perlu diganti setiap minggu.","citations":[{"source_id":"S1","quote":"Evidence text 4"}]}']]],
            ]),
        ]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan bearing', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $answer = (string) DB::table('agent_conversation_messages')->where('role', 'assistant')->value('content');
        $this->assertStringContainsString('belum dapat memastikan', $answer);
        $this->assertStringNotContainsString('diganti setiap minggu', $answer);
        $this->assertStringNotContainsString('Evidence text 4', $answer);
    }

    public function test_fabricated_source_identifier_fails_closed_as_abstention(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"status":"answer","citations":[{"source_id":"S1","quote":"Evidence text 4"},{"source_id":"S2","quote":"Evidence text 4"}]}']]],
            ]),
        ]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $this->assertStringContainsString(
            'belum dapat memastikan',
            (string) DB::table('agent_conversation_messages')->where('role', 'assistant')->value('content'),
        );
    }

    public function test_unpublished_document_is_excluded_from_evidence(): void
    {
        KnowledgeDocument::factory()->create([
            'document_code' => 'DOC-7',
            'title' => 'Manual FNC-01',
            'version' => '2.0',
            'status' => 'ready',
            'is_published' => false,
        ]);
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]])]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $this->assertStringContainsString(
            'belum dapat memastikan',
            (string) DB::table('agent_conversation_messages')->where('role', 'assistant')->value('content'),
        );
        Http::assertSentCount(1); // tidak ada generation call tanpa bukti terbit.
    }

    public function test_processing_document_is_excluded_from_evidence(): void
    {
        KnowledgeDocument::factory()->create([
            'document_code' => 'DOC-7',
            'title' => 'Manual FNC-01',
            'version' => '2.0',
            'status' => 'processing',
            'is_published' => true,
        ]);
        $this->app->instance(VectorStore::class, new StaticRetrievalStore([$this->hit(0.93)]));
        Http::fake(['provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]])]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $this->assertStringContainsString(
            'belum dapat memastikan',
            (string) DB::table('agent_conversation_messages')->where('role', 'assistant')->value('content'),
        );
        Http::assertSentCount(1); // dokumen belum terbit tidak memicu generation.
    }

    public function test_multiple_eligible_chat_providers_fail_safe_without_answers(): void
    {
        $this->activateChatRevision();
        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect()
            ->assertSessionHas('flash_error');

        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
    }

    public function test_provider_timeout_fails_safely_without_partial_messages(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response(['error' => ['message' => 'gateway timeout']], 408),
        ]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect()
            ->assertSessionHas('flash_error');

        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
    }

    public function test_non_contract_provider_output_fails_closed_as_abstention(): void
    {
        $this->publishedDocument();
        $store = new StaticRetrievalStore([$this->hit(0.93)]);
        $this->app->instance(VectorStore::class, $store);
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]]),
            'provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Maaf, terjadi kesalahan pada layanan AI. Coba lagi nanti.']]],
            ]),
        ]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $messages = DB::table('agent_conversation_messages')->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('belum dapat memastikan', $messages[1]->content);
        $this->assertSame('[]', json_encode(json_decode($messages[1]->meta, true)['citations']));
        $this->assertStringNotContainsString('kesalahan pada layanan AI', $messages[1]->content);
    }

    public function test_evidence_context_is_bounded_to_complete_excerpts_with_source_cap(): void
    {
        $this->publishedDocument();
        $hits = [];
        for ($index = 1; $index <= 6; $index++) {
            $hits[] = $this->hit(0.93, 'EXC-'.$index.'-'.str_repeat('x', 3000), $index);
        }
        $this->app->instance(VectorStore::class, new StaticRetrievalStore($hits));
        $bodies = [];
        Http::fake(function ($request) use (&$bodies) {
            $bodies[] = $request->body();

            return str_contains((string) $request->url(), '/embeddings')
                ? Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]])
                : Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"status":"abstain","citations":[]}']]]]);
        });

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Ringkas kriteria', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $chatBody = (string) end($bodies);
        $this->assertSame(5, substr_count($chatBody, 'Excerpt (untrusted):'));
        foreach ([1, 2, 3, 4, 5] as $index) {
            $this->assertStringContainsString('EXC-'.$index.'-'.str_repeat('x', 3000), $chatBody);
        }
        $this->assertStringNotContainsString('EXC-6', $chatBody);
        $this->assertLessThan(22000, strlen($chatBody)); // batas konteks + prompt, tanpa kutipan terpotong.
    }

    public function test_utf8_context_budget_never_slices_an_excerpt_or_metadata(): void
    {
        $this->publishedDocument();
        $large = str_repeat('語', 3000);
        $hits = [
            $this->hit(0.93, 'UTF8-FIRST-'.$large, 1),
            $this->hit(0.92, 'UTF8-SECOND-'.$large, 2),
        ];
        $this->app->instance(VectorStore::class, new StaticRetrievalStore($hits));
        $bodies = [];
        Http::fake(function ($request) use (&$bodies) {
            $bodies[] = $request->body();

            return str_contains((string) $request->url(), '/embeddings')
                ? Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 3072, 0.25)]]])
                : Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"status":"abstain","citations":[]}']]]]);
        });

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'Pertanyaan', 'request_id' => (string) Str::uuid()])
            ->assertRedirect();

        $rawChatBody = (string) end($bodies);
        $chatBody = json_decode($rawChatBody, true, flags: JSON_THROW_ON_ERROR);
        $decodedBody = json_encode($chatBody, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertSame(1, substr_count($decodedBody, 'Excerpt (untrusted):'));
        $this->assertStringContainsString('UTF8-FIRST-'.$large, $decodedBody);
        $this->assertStringNotContainsString('UTF8-SECOND-', $decodedBody);
        $this->assertLessThan(19_000, strlen($decodedBody));
    }

    public function test_owner_can_delete_conversation_and_messages_cascade(): void
    {
        $conversationId = $this->createConversation();
        $conversation = Conversation::query()->findOrFail($conversationId);
        $conversation->messages()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => $this->admin->getMorphClass(),
            'participant_id' => $this->admin->getKey(),
            'agent' => 'prime-chat-rag',
            'role' => 'user',
            'content' => 'pesan',
            'attachments' => [], 'steps' => [], 'usage' => [], 'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $this->actingAs($this->admin)
            ->delete("/assistant/conversations/{$conversationId}")
            ->assertRedirect(route('assistant.index'))
            ->assertSessionHas('flash_success', 'Percakapan dihapus.');

        $this->assertDatabaseMissing('agent_conversations', ['id' => $conversationId]);
        $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $conversationId]);
    }

    public function test_another_admin_cannot_delete_or_answer_someone_elses_conversation(): void
    {
        $conversationId = $this->createConversation();
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);

        $this->actingAs($other)->delete("/assistant/conversations/{$conversationId}")->assertNotFound();
        $this->actingAs($other)->post("/assistant/conversations/{$conversationId}/messages", ['message' => 'intrusi', 'request_id' => (string) Str::uuid()])->assertNotFound();

        $this->assertDatabaseHas('agent_conversations', ['id' => $conversationId]);
    }

    public function test_operator_cannot_delete_conversations(): void
    {
        $conversationId = $this->createConversation();
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($operator)->delete("/assistant/conversations/{$conversationId}")->assertRedirect('/403');
        $this->assertDatabaseHas('agent_conversations', ['id' => $conversationId]);
    }

    private function createConversation(): string
    {
        return basename((string) $this->actingAs($this->admin)->post('/assistant/conversations', [])->headers->get('Location'));
    }

    private function publishedDocument(): void
    {
        KnowledgeDocument::factory()->create([
            'document_code' => 'DOC-7',
            'title' => 'Manual FNC-01',
            'version' => '2.0',
            'status' => 'ready',
            'page_count' => 4,
            'is_published' => true,
        ]);
    }

    private function hit(float $score, string $text = 'Evidence text 4', int $chunkIndex = 4): VectorSearchResult
    {
        return new VectorSearchResult(
            'chunk-'.$chunkIndex,
            $score,
            'DOC-7',
            '2.0',
            'rag:config-2:revision-1:text-embedding-3-large:3072:cosine',
            'p2-4',
            2,
            $text,
            [
                'document_code' => 'DOC-7',
                'document_version' => '2.0',
                'section_identifier' => '3.4',
                'section_title' => 'Inspection criteria',
                'page_start' => 2,
                'page_end' => 4,
                'chunk_index' => $chunkIndex,
                'stable_chunk_identity' => 'stable-'.$chunkIndex,
            ],
        );
    }

    private function pinEndpoint(): void
    {
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
    }

    private function activateChatRevision(array $overrides = []): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create(['display_name' => 'Chat', 'purpose' => 'chat']);
        $revision = new AiProviderConfigurationRevision;
        $revision->forceFill(array_merge([
            'configuration_id' => $configuration->id,
            'revision_number' => 1,
            'driver' => 'openai-compatible',
            'model_id' => 'vendor/chat-model',
            'base_url' => 'https://provider.example.test/v1',
            'credential' => 'chat-secret',
            'generation_parameters' => ['temperature' => 0.2, 'max_tokens' => 512],
            'test_state' => 'passed',
            'test_attempt_id' => 'attempt-1',
            'tested_at' => now(),
        ], $overrides))->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function activateEmbeddingRevision(): void
    {
        $configuration = AiProviderConfiguration::query()->create(['display_name' => 'Embedding', 'purpose' => 'embedding']);
        $revision = new AiProviderConfigurationRevision;
        $revision->forceFill([
            'configuration_id' => $configuration->id,
            'revision_number' => 1,
            'driver' => 'openai-compatible',
            'model_id' => 'text-embedding-3-large',
            'base_url' => 'https://provider.example.test/v1',
            'credential' => 'embed-secret',
            'test_state' => 'passed',
            'test_attempt_id' => 'attempt-1',
            'tested_at' => now(),
            'tested_embedding_dimensions' => 3072,
        ])->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();
    }
}

final class StaticRetrievalStore implements VectorStore
{
    /** @param list<VectorSearchResult> $results */
    public function __construct(private readonly array $results) {}

    public function isReady(): bool
    {
        return true;
    }

    public function ensureCollection(VectorSpace $space): void {}

    public function upsert(VectorSpace $space, string $documentId, string $documentVersion, array $chunks, bool $retrievalActive = true): void {}

    /**
     * @return list<VectorSearchResult>
     */
    public function search(VectorSpace $space, RetrievalQuery $query): array
    {
        return $this->results;
    }

    public function deleteVersion(string $documentId, string $documentVersion): void {}

    public function setRetrievalActive(VectorSpace $space, string $documentId, string $documentVersion, bool $active): void {}
}
