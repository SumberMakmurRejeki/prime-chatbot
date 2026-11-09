<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integrasi chatbot Admin dengan Gemini File Search.
 *
 * Seluruh kasus berjalan lewat endpoint percakapan yang sama dengan produksi
 * (binding container default), sehingga yang dibuktikan adalah perilaku Admin,
 * bukan pemanggilan service langsung. Permintaan keluar selalu di-hambat
 * Http::fake dan tidak ada jaringan nyata.
 */
class GeminiAssistantChatTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const STORE = 'fileSearchStores/prime-dev-store';

    private const CREDENTIAL = 'fictional-prime-gemini-key';

    private User $admin;

    private AiProviderConfigurationRevision $revision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->revision = $this->activateGeminiRevision();
        config(['gemini_file_search.indexing.store' => 'prime-dev-store']);
        // Resolver DNS PRIME diganti agar suite tidak menyentuh jaringan nyata.
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        Http::preventStrayRequests();
    }

    public function test_admin_chat_answers_from_current_published_sop_revision_with_a_verified_citation(): void
    {
        $historical = $this->sop('SOP-UPCAST-001', '2.0', false);
        $current = $this->sop('SOP-UPCAST-001', '3.0', true, 'SOP Upcast');
        $draft = $this->sop('SOP-UPCAST-001', '4.0', false);
        $this->index($historical, ['import_identity' => $this->identity('1')]);
        $currentIndex = $this->index($current, ['import_identity' => $this->identity('2')]);
        $this->index($draft, ['import_identity' => $this->identity('3')]);

        $this->fakeAnswer('Tekanan operasi normal adalah 2,5–3,5 bar.', [[
            'file_name' => 'upcast-rev03',
            'page_number' => 3,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '3.0',
                'import_identity' => $currentIndex->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $requestId = (string) Str::uuid();
        $this->postJson("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => $requestId,
        ])->assertOk()->assertJson(['message' => 'Jawaban tersimpan.', 'request_id' => $requestId]);

        $assistant = $this->assistantMessage();
        $this->assertSame('Tekanan operasi normal adalah 2,5–3,5 bar.', $assistant->content);
        $citation = json_decode((string) $assistant->meta, true)['citations'][0];
        $this->assertSame('S1', $citation['source']);
        $this->assertSame('SOP-UPCAST-001', $citation['code']);
        $this->assertSame('SOP Upcast', $citation['title']);
        $this->assertSame('3.0', $citation['version']);
        $this->assertSame('3', $citation['page']);
        $this->assertSame('upcast-rev03', $citation['locator']);

        // Hanya revisi terbit saat ini yang boleh masuk filter dua kondisi; revisi
        // historis dan draft tidak punya token yang diotorisasi.
        Http::assertSentCount(1);
        $filter = $this->sentFilter();
        $this->assertSame('(document_code="SOP-UPCAST-001" AND import_identity="'.$currentIndex->import_identity.'")', $filter);
        $this->assertStringNotContainsString('version=', $filter);
    }

    public function test_answer_instructions_are_localized_and_preserve_technical_facts(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true, 'SOP Upcast');
        $index = $this->index($document);
        $this->fakeAnswer('Berdasarkan SOP Upcast Rev.03, kode pemeriksaannya adalah UP-COPPER-317.', [[
            'page_number' => 3,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '3.0',
                'import_identity' => $index->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->postJson("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Apa kode pemeriksaannya?', 'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $view = $this->get("/assistant/conversations/{$conversationId}");

        $this->assertSame(
            'Berdasarkan SOP Upcast Rev.03, kode pemeriksaannya adalah UP-COPPER-317.',
            $this->assistantMessage()->content,
        );
        $view->assertOk()
            ->assertSee('versi 3.0')
            ->assertSee('halaman 3');

        Http::assertSent(function ($request): bool {
            $this->assertStringContainsString('Jawab dengan bahasa Indonesia yang alami, jelas, dan ringkas', (string) data_get($request->data(), 'system_instruction'));

            return true;
        });

    }

    /** Otorisasi sumber ditetapkan PRIME di instruksi jawaban, bukan dari isi dokumen. */
    public function test_answer_instructions_place_source_authorization_with_prime_not_document_wording(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true, 'SOP Upcast');
        $index = $this->index($document);

        $this->fakeAnswer('Kode pemeriksaan yang berlaku adalah UP-COPPER-317.', [[
            'page_number' => 1,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '3.0',
                'import_identity' => $index->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            // Pertanyaan berbingkai produksi yang pada verifikasi live dijawab abstain keliru.
            'message' => 'Apa kode pemeriksaan yang berlaku menurut SOP Upcast terbaru yang diterbitkan?',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        Http::assertSent(function ($request): bool {
            $instruction = (string) data_get($request->data(), 'system_instruction');
            $this->assertStringContainsString('Otorisasi sumber ditetapkan PRIME', $instruction);
            $this->assertStringContainsString('jangan menilai ulang status terbit', $instruction);

            return true;
        });
    }

    /** Penanda uji/sintetis pada dokumen terotorisasi tidak boleh memicu penolakan jawaban. */
    public function test_answer_instructions_answer_facts_from_authorized_test_documents_without_presenting_them_as_operational_guidance(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true, 'SOP Upcast');
        $index = $this->index($document);

        $this->fakeAnswer('Kode pemeriksaan yang berlaku adalah UP-COPPER-317.', [[
            'page_number' => 1,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '3.0',
                'import_identity' => $index->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Apa kode pemeriksaan yang berlaku?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        Http::assertSent(function ($request): bool {
            $instruction = (string) data_get($request->data(), 'system_instruction');
            $this->assertStringContainsString('uji, simulasi, atau sintetis', $instruction);
            $this->assertStringContainsString('jangan menolak menjawab hanya karena dokumen menyatakan dirinya bukan SOP resmi', $instruction);
            $this->assertStringContainsString('Jangan menyajikan isi berpenanda uji atau sintetis sebagai panduan operasional nyata yang disetujui', $instruction);

            return true;
        });
    }

    /** Abstain tetap wajib, tetapi hanya untuk bukti yang kurang, saling bertentangan, atau tidak cukup. */
    public function test_answer_instructions_limit_abstention_to_missing_contradictory_or_insufficient_evidence(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true, 'SOP Upcast');
        $index = $this->index($document);

        $this->fakeAnswer('Kode pemeriksaan yang berlaku adalah UP-COPPER-317.', [[
            'page_number' => 1,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '3.0',
                'import_identity' => $index->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Apa kode pemeriksaan yang berlaku?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        Http::assertSent(function ($request): bool {
            $instruction = (string) data_get($request->data(), 'system_instruction');
            $this->assertStringContainsString('hanya jika dokumen terotorisasi tidak memuat fakta yang diminta', $instruction);
            $this->assertStringContainsString('saling bertentangan', $instruction);
            $this->assertStringContainsString('belum dapat dipastikan', $instruction);

            return true;
        });
    }

    public function test_assistant_markdown_is_rendered_safely_and_technical_locator_stays_hidden(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true, 'SOP Upcast');
        $index = $this->index($document);
        $this->fakeAnswer('Kode pemeriksaannya adalah **UP-COPPER-317** <script>alert(1)</script> dan **<img src=x onerror=alert(2)>**.', [[
            'file_name' => 'upcast-rev03',
            'page_number' => 3,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '3.0',
                'import_identity' => $index->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Apa kode pemeriksaannya?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->get("/assistant/conversations/{$conversationId}")
            ->assertOk()
            ->assertSee('<strong>UP-COPPER-317</strong>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('SOP Upcast (SOP-UPCAST-001), versi 3.0, halaman 3', false)
            ->assertDontSee('upcast-rev03')
            ->assertDontSee('fileSearchStores/prime-dev-store');

        $stored = (string) $this->assistantMessage()->content;
        $this->assertStringContainsString('**UP-COPPER-317**', $stored);
    }

    public function test_previously_stored_conversation_history_remains_readable(): void
    {
        $conversationId = $this->createConversation();
        $meta = ['citations' => [[
            'source' => 'S1', 'code' => 'SOP-UPCAST-001', 'title' => 'SOP Upcast', 'version' => '3.0',
            'locator' => 'fileSearchStores/prime-dev-store', 'page' => '3', 'chunk' => '',
        ]], 'request_id' => (string) Str::uuid()];

        DB::table('agent_conversation_messages')->insert([[
            'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId,
            'participant_type' => User::class, 'participant_id' => $this->admin->id,
            'agent' => 'prime-chat-rag', 'role' => 'user', 'content' => 'Pertanyaan lama',
            'attachments' => '[]', 'steps' => '[]', 'usage' => '[]', 'meta' => json_encode(['request_id' => $meta['request_id']]),
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ], [
            'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId,
            'participant_type' => User::class, 'participant_id' => $this->admin->id,
            'agent' => 'prime-chat-rag', 'role' => 'assistant', 'content' => 'Kode **UP-COPPER-317** tersimpan.',
            'attachments' => '[]', 'steps' => '[]', 'usage' => '[]', 'meta' => json_encode($meta),
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]]);

        $this->get("/assistant/conversations/{$conversationId}")
            ->assertOk()
            ->assertSee('Pertanyaan lama')
            ->assertSee('Kode <strong>UP-COPPER-317</strong> tersimpan.', false)
            ->assertSee('SOP Upcast (SOP-UPCAST-001), versi 3.0, halaman 3', false)
            ->assertDontSee('fileSearchStores/prime-dev-store');
    }

    public function test_empty_message_validation_is_returned_in_indonesian(): void
    {
        $conversationId = $this->createConversation();

        $this->postJson("/assistant/conversations/{$conversationId}/messages", [
            'message' => '', 'request_id' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonPath('errors.message.0', 'Pesan wajib diisi.');

        Http::assertNothingSent();
    }

    public function test_fnc01_published_revision_is_answered_with_a_verified_citation(): void
    {
        $document = $this->sop('FNC-01', '1.0', true, 'Manual FNC-01');
        $index = $this->index($document);

        $this->fakeAnswer('Interval inspeksi pelumasan adalah 14 hari.', [[
            'file_name' => 'fnc01-rev01',
            'custom_metadata' => [
                'document_code' => 'FNC-01',
                'version' => '1.0',
                'import_identity' => $index->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa interval pelumasan FNC-01?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect("/assistant/conversations/{$conversationId}");

        $citation = json_decode((string) $this->assistantMessage()->meta, true)['citations'][0];
        $this->assertSame('FNC-01', $citation['code']);
        $this->assertSame('1.0', $citation['version']);
        $this->assertNull($citation['page']);

        $this->get("/assistant/conversations/{$conversationId}")
            ->assertOk()
            ->assertSee('Manual FNC-01')
            ->assertSee('versi 1.0');
    }

    public function test_historical_revision_never_enters_the_retrieval_filter_or_the_trusted_map(): void
    {
        $historical = $this->sop('SOP-UPCAST-001', '2.0', false);
        $historicalIndex = $this->index($historical, ['import_identity' => $this->identity('4')]);
        $current = $this->sop('SOP-UPCAST-001', '3.0', true);
        $currentIndex = $this->index($current, ['import_identity' => $this->identity('5')]);

        // Anotasi revisi historis tidak boleh lolos meskipun provider melaporkannya.
        $this->fakeAnswer('Jawaban dari revisi historis.', [[
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '2.0',
                'import_identity' => $historicalIndex->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        $filter = $this->sentFilter();
        $this->assertSame('(document_code="SOP-UPCAST-001" AND import_identity="'.$currentIndex->import_identity.'")', $filter);
        $this->assertStringNotContainsString($historicalIndex->import_identity, $filter);

        $assistant = $this->assistantMessage();
        $this->assertStringContainsString('belum dapat memastikan', (string) $assistant->content);
        $this->assertSame([], json_decode((string) $assistant->meta, true)['citations']);
    }

    public function test_unpublished_draft_revision_never_enters_the_retrieval_filter_or_the_trusted_map(): void
    {
        $current = $this->sop('SOP-UPCAST-001', '3.0', true);
        $currentIndex = $this->index($current, ['import_identity' => $this->identity('6')]);
        $draft = $this->sop('SOP-UPCAST-001', '4.0', false);
        $draftIndex = $this->index($draft, ['import_identity' => $this->identity('7')]);

        $this->fakeAnswer('Jawaban dari draft.', [[
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '4.0',
                'import_identity' => $draftIndex->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        $filter = $this->sentFilter();
        $this->assertStringContainsString($currentIndex->import_identity, $filter);
        $this->assertStringNotContainsString($draftIndex->import_identity, $filter);

        $assistant = $this->assistantMessage();
        $this->assertStringContainsString('belum dapat memastikan', (string) $assistant->content);
        $this->assertSame([], json_decode((string) $assistant->meta, true)['citations']);
    }

    public function test_no_eligible_published_sop_fails_closed_without_any_file_search_request(): void
    {
        $unpublished = $this->sop('SOP-IDLE-1', '1.0', false);
        $this->index($unpublished);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        $assistant = $this->assistantMessage();
        $this->assertStringContainsString('belum dapat memastikan', (string) $assistant->content);
        $this->assertSame([], json_decode((string) $assistant->meta, true)['citations']);
        Http::assertNothingSent();
    }

    public function test_store_and_credential_provenance_mismatch_fails_closed(): void
    {
        $otherStore = $this->sop('SOP-STORE-1', '1.0', true);
        $this->index($otherStore, [
            'store_id' => 'fileSearchStores/store-other',
            'google_document_name' => 'fileSearchStores/store-other/documents/doc-1',
        ]);

        $staleCredential = $this->sop('SOP-CRED-1', '1.0', true);
        $this->index($staleCredential, [
            'credential_configuration_id' => 999999,
            'credential_revision_id' => 999999,
        ]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        $assistant = $this->assistantMessage();
        $this->assertStringContainsString('belum dapat memastikan', (string) $assistant->content);
        $this->assertSame([], json_decode((string) $assistant->meta, true)['citations']);
        Http::assertNothingSent();
    }

    public function test_citation_with_mismatched_import_identity_is_never_presented_as_verified_guidance(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true);
        $this->index($document);

        $this->fakeAnswer('Klaim yang tidak dapat diverifikasi.', [[
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '3.0',
                'import_identity' => $this->identity('8'),
            ],
        ]]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();

        $assistant = $this->assistantMessage();
        $this->assertStringContainsString('belum dapat memastikan', (string) $assistant->content);
        $this->assertStringNotContainsString('Klaim yang tidak dapat diverifikasi', (string) $assistant->content);
        $this->assertSame([], json_decode((string) $assistant->meta, true)['citations']);
    }

    public function test_operator_is_rejected_server_side_before_any_gemini_call(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true);
        $this->index($document);
        $conversationId = $this->createConversation();

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($operator)->get('/assistant')->assertRedirect('/403');
        $this->actingAs($operator)->post('/assistant/conversations', [])->assertRedirect('/403');
        $this->actingAs($operator)->get("/assistant/conversations/{$conversationId}")->assertRedirect('/403');
        $this->actingAs($operator)->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'intrusi', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect('/403');
        $this->actingAs($operator)->delete("/assistant/conversations/{$conversationId}")->assertRedirect('/403');

        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
        Http::assertNothingSent();
    }

    public function test_conversation_response_and_persistence_contract_stays_compatible(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true, 'SOP Upcast');
        $index = $this->index($document);

        $this->fakeAnswer('Tekanan operasi normal adalah 2,5–3,5 bar.', [[
            'file_name' => 'upcast-rev03',
            'page_number' => 3,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '3.0',
                'import_identity' => $index->import_identity,
            ],
        ]]);

        $conversationId = $this->createConversation();
        $requestId = (string) Str::uuid();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => $requestId,
        ])->assertRedirect("/assistant/conversations/{$conversationId}")
            ->assertSessionHas('flash_success', 'Jawaban AI tersimpan.');

        $messages = DB::table('agent_conversation_messages')->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('prime-chat-rag', $messages[1]->agent);
        $this->assertSame(['citations', 'request_id'], array_keys(json_decode((string) $messages[1]->meta, true)));

        $this->get("/assistant/conversations/{$conversationId}")
            ->assertOk()
            ->assertSee('SOP Upcast')
            ->assertSee('versi 3.0')
            ->assertSee('halaman 3');
    }

    public function test_gemini_failure_is_a_controlled_error_without_any_qdrant_fallback(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true);
        $this->index($document);

        Http::fake([self::BASE_URL.'/interactions' => Http::response(['error' => ['message' => 'upstream down']], 500)]);

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect()
            ->assertSessionHas('flash_error', 'Assistant tidak dapat menjawab saat ini. Periksa konfigurasi Chat Provider dan layanan Knowledge Base, lalu coba lagi.');

        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
        // Satu-satunya permintaan keluar adalah Gemini; tidak ada retrieval lain
        // (mis. embedding/Qdrant) yang dipanggil sebagai pengganti.
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/interactions'));
    }

    /**
     * Kandidat disahkan oleh revisi kredensial yang aktif saat evaluasi, lalu
     * Admin mengaktifkan revisi lain sebelum jawaban dibangkitkan. Permintaan
     * harus gagal tertutup: tidak ada File Search yang dikirim dengan kredensial
     * yang tidak mengesahkan kelayakan, dan tidak ada jawaban yang tersimpan.
     */
    public function test_credential_revision_change_between_eligibility_and_generation_fails_closed(): void
    {
        $document = $this->sop('SOP-UPCAST-001', '3.0', true);
        $this->index($document);

        $swapped = false;
        DB::listen(function ($query) use (&$swapped): void {
            if ($swapped || ! str_starts_with(strtolower(trim($query->sql)), 'select')) {
                return;
            }

            if (! str_contains($query->sql, 'knowledge_documents')) {
                return;
            }

            // Kandidat sudah dievaluasi; aktivasi revisi kedua terjadi tepat
            // sebelum kredensial untuk generasi diresolusi.
            $swapped = true;
            $configuration = AiProviderConfiguration::query()->findOrFail($this->revision->configuration_id);
            $replacement = $configuration->revisions()->make([
                'driver' => 'gemini',
                'model_id' => 'gemini-3.5-flash-lite',
                'credential' => self::CREDENTIAL,
                'enabled' => true,
            ])->forceFill([
                'revision_number' => 2,
                'test_state' => 'passed',
                'test_attempt_id' => 'fictional-attempt-id-2',
                'test_started_at' => now(),
                'tested_at' => now(),
            ]);
            $replacement->save();
            $configuration->forceFill(['active_revision_id' => $replacement->id])->save();
        });

        $conversationId = $this->createConversation();
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Berapa tekanan operasi Upcast?', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect()
            ->assertSessionHas('flash_error', 'Assistant tidak dapat menjawab saat ini. Periksa konfigurasi Chat Provider dan layanan Knowledge Base, lalu coba lagi.');

        $this->assertTrue($swapped, 'Revisi kredensial tidak pernah berganti selama evaluasi.');
        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
        Http::assertNothingSent();
    }

    private function sop(string $code, string $version, bool $published, ?string $title = null): KnowledgeDocument
    {
        return KnowledgeDocument::factory()->create([
            'document_code' => $code,
            'title' => $title ?? 'SOP '.$code,
            'version' => $version,
            'status' => 'ready',
            'is_published' => $published,
        ]);
    }

    private function index(KnowledgeDocument $document, array $overrides = []): KnowledgeDocumentGeminiIndex
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
            'credential_configuration_id' => $this->revision->configuration_id,
            'credential_revision_id' => $this->revision->id,
            'import_identity' => $this->identity('9'),
        ], $overrides));
    }

    /**
     * Token generasi impor kanonik PRIME: 'g' + 48 hex, unik tiap pemanggilan
     * sehingga dua baris indeksasi tidak pernah berbagi identitas.
     */
    private function identity(string $seed): string
    {
        return 'g'.substr(hash('sha256', $seed.random_bytes(16)), 0, 48);
    }

    /** @param list<array<string, mixed>> $annotations */
    private function fakeAnswer(string $text, array $annotations): void
    {
        Http::fake([self::BASE_URL.'/interactions' => Http::response([
            'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [[
                'type' => 'text',
                'text' => $text,
                'annotations' => array_map(static fn (array $annotation): array => array_merge([
                    'type' => 'file_citation',
                    'document_uri' => self::STORE,
                ], $annotation), $annotations),
            ]]]],
        ])]);
    }

    private function createConversation(): string
    {
        return basename((string) $this->actingAs($this->admin)->post('/assistant/conversations', [])->headers->get('Location'));
    }

    private function assistantMessage(): object
    {
        return DB::table('agent_conversation_messages')->where('role', 'assistant')->firstOrFail();
    }

    private function sentFilter(): string
    {
        $endpoint = self::BASE_URL.'/interactions';
        $filter = null;

        Http::assertSent(function ($request) use ($endpoint, &$filter): bool {
            if ($request->url() === $endpoint) {
                $filter = (string) $request['tools'][0]['metadata_filter'];
            }

            return true;
        });

        $this->assertIsString($filter, 'Permintaan File Search ber-scope tidak pernah dikirim.');

        return $filter;
    }

    private function activateGeminiRevision(): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Fictional Gemini Chat',
            'purpose' => 'chat',
        ]);
        $revision = $configuration->revisions()->make([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.5-flash-lite',
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
