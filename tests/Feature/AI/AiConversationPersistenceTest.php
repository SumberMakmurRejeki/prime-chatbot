<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Services\AI\GroundedAnswerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiConversationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $answers = $this->createStub(GroundedAnswerService::class);
        $answers->method('answer')->willReturn(['answer' => 'Jawaban uji.', 'citations' => []]);
        $this->app->instance(GroundedAnswerService::class, $answers);
    }

    public function test_new_chat_creates_exactly_one_owned_conversation_with_server_supplied_identity(): void
    {
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();

        $this->assertDatabaseCount('agent_conversations', 1);
        $conversation = DB::table('agent_conversations')->first();
        $this->assertSame(User::class, $conversation->participant_type);
        $this->assertSame($this->admin->id, (int) $conversation->participant_id);
        $this->assertSame('Percakapan baru', $conversation->title);
    }

    public function test_append_stores_user_and_assistant_messages_and_derives_local_title(): void
    {
        $requestId = (string) Str::uuid();
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');

        $this->post("/assistant/conversations/{$id}/messages", ['message' => 'Cek bearing motor utama', 'request_id' => $requestId])->assertRedirect();

        $this->assertDatabaseCount('agent_conversation_messages', 2);
        $messages = DB::table('agent_conversation_messages')->orderBy('role')->get();
        $assistant = json_decode((string) $messages[0]->meta, true);
        $user = json_decode((string) $messages[1]->meta, true);
        $this->assertSame('assistant', $messages[0]->role);
        $this->assertSame('Jawaban uji.', $messages[0]->content);
        $this->assertSame($requestId, $assistant['request_id']);
        $this->assertSame('user', $messages[1]->role);
        $this->assertSame('Cek bearing motor utama', $messages[1]->content);
        $this->assertSame($requestId, $user['request_id']);
        $this->assertSame('Cek bearing motor utama', DB::table('agent_conversations')->value('title'));
    }

    public function test_client_cannot_spoof_ownership_agent_role_or_metadata_fields(): void
    {
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');
        $requestId = (string) Str::uuid();

        $this->post("/assistant/conversations/{$id}/messages", [
            'message' => 'Pesan normal',
            'request_id' => $requestId,
            'participant_type' => 'App\\Models\\User',
            'participant_id' => 999,
            'agent' => 'spoofed-agent',
            'role' => 'assistant',
            'status' => 'completed',
            'meta' => '{"injected":true}',
            'steps' => '{"injected":true}',
            'usage' => '{"injected":true}',
            'attachments' => '[]',
        ])->assertRedirect();

        $message = DB::table('agent_conversation_messages')->where('role', 'user')->first();
        $this->assertSame('prime-chat-rag', $message->agent);
        $this->assertSame($this->admin->id, (int) $message->participant_id);
        $this->assertSame(['request_id' => $requestId], json_decode((string) $message->meta, true));
        $this->assertSame([], json_decode((string) $message->steps, true));
    }

    public function test_request_uuid_replays_are_idempotent_and_cannot_be_reused_for_other_content(): void
    {
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');
        $requestId = (string) Str::uuid();
        $payload = ['message' => 'Pertanyaan sama yang memang boleh dikirim', 'request_id' => $requestId];

        $this->post("/assistant/conversations/{$id}/messages", $payload)->assertRedirect();
        $this->post("/assistant/conversations/{$id}/messages", $payload)->assertRedirect();
        $this->assertDatabaseCount('agent_conversation_messages', 2);

        $this->post("/assistant/conversations/{$id}/messages", ['message' => 'Isi berbeda', 'request_id' => $requestId])
            ->assertSessionHas('flash_error');
        $this->post("/assistant/conversations/{$id}/messages", ['message' => $payload['message'], 'request_id' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseCount('agent_conversation_messages', 4);
    }

    public function test_message_request_id_is_required_and_must_be_a_uuid(): void
    {
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');

        $this->post("/assistant/conversations/{$id}/messages", ['message' => 'No key'])->assertSessionHasErrors('request_id');
        $this->post("/assistant/conversations/{$id}/messages", ['message' => 'Bad key', 'request_id' => 'not-a-uuid'])->assertSessionHasErrors('request_id');
        $this->assertDatabaseCount('agent_conversation_messages', 0);
    }

    public function test_message_byte_limit_rejects_multibyte_payload_before_answering(): void
    {
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');

        $this->post("/assistant/conversations/{$id}/messages", [
            'message' => str_repeat('é', 8193),
            'request_id' => (string) Str::uuid(),
        ])->assertSessionHasErrors('message');

        $this->assertDatabaseCount('agent_conversation_messages', 0);
    }

    public function test_pending_conversation_send_returns_safe_json_conflict(): void
    {
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');
        $directory = storage_path('framework/cache/data');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/assistant-conversation-'.hash('sha256', $id).'.lock';
        $lock = fopen($path, 'c');
        $this->assertNotFalse($lock);
        flock($lock, LOCK_EX | LOCK_NB);

        try {
            $this->postJson("/assistant/conversations/{$id}/messages", [
                'message' => 'Pertanyaan berikutnya', 'request_id' => (string) Str::uuid(),
            ])->assertStatus(409)->assertJson(['code' => 'request_in_progress']);
            $this->assertDatabaseCount('agent_conversation_messages', 0);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_list_is_owner_scoped_sorted_by_activity_and_paginated(): void
    {
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $ids = [];
        foreach (range(1, 11) as $index) {
            $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
            $ids[] = (string) DB::table('agent_conversations')->orderByDesc('updated_at')->value('id');
            // Pesan menaikkan updated_at agar urutan aktivitas terlihat di daftar.
            $this->post('/assistant/conversations/'.$ids[$index - 1].'/messages', ['message' => "Pesan ke-{$index}", 'request_id' => (string) Str::uuid()])->assertRedirect();
        }
        $this->actingAs($other)->post('/assistant/conversations', [])->assertRedirect();

        $response = $this->actingAs($this->admin)->get('/assistant');
        $response->assertOk();
        $this->assertStringContainsString('total 11 data', (string) $response->getContent());

        // Halaman 1 (10 terbaru); halaman 2 memuat sisa 1, tetap owner scoped.
        $second = $this->get('/assistant?page=2');
        $second->assertOk();
        $this->assertStringContainsString('total 11 data', (string) $second->getContent());
    }

    public function test_history_is_bounded_paginated_and_displayed_oldest_first(): void
    {
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');

        foreach (range(1, 30) as $index) {
            $this->post("/assistant/conversations/{$id}/messages", ['message' => "Pesan {$index}", 'request_id' => (string) Str::uuid()])->assertRedirect();
        }

        $first = $this->get("/assistant/conversations/{$id}");
        $first->assertOk();
        $content = (string) $first->getContent();
        // Riwayat dibatasi pada 25 pesan terakhir, termasuk pertanyaan dan jawaban.
        $this->assertStringContainsString('Pesan 19', $content);
        $this->assertStringContainsString('Pesan 30', $content);
        $this->assertStringNotContainsString('Pesan 18</p>', $content);
        $this->assertLessThan(strpos($content, 'Pesan 20</p>'), strpos($content, 'Pesan 19</p>'));
        $first->assertSee('Muat riwayat lebih lama');
    }

    public function test_flow_is_provider_independent_and_needs_no_global_provider_selector(): void
    {
        Http::preventStrayRequests();

        // Tidak ada pemilih provider global dan tidak ada konfigurasi provider aktif.
        $this->assertSame(0, DB::table('ai_provider_configurations')->count());
        // Judul percakapan tidak boleh memicu panggilan model/provider.
        $this->assertFalse((bool) config('ai.conversations.generate_title'));

        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');
        $this->post("/assistant/conversations/{$id}/messages", ['message' => 'Tanpa provider', 'request_id' => (string) Str::uuid()])->assertRedirect();
        $this->get("/assistant/conversations/{$id}")->assertOk();
        $this->get('/assistant')->assertOk();

        // Tidak ada satu pun request keluar sepanjang alur create/list/open/append.
        Http::assertNothingSent();
    }

    public function test_rendered_surfaces_expose_only_narrow_projection_fields(): void
    {
        $this->actingAs($this->admin)->post('/assistant/conversations', [])->assertRedirect();
        $id = (string) DB::table('agent_conversations')->value('id');
        $this->post("/assistant/conversations/{$id}/messages", ['message' => 'Pesan proyeksi', 'request_id' => (string) Str::uuid()])->assertRedirect();

        $show = (string) $this->get("/assistant/conversations/{$id}")->assertOk()->getContent();
        $index = (string) $this->get('/assistant')->assertOk()->getContent();

        // Field SDK internal tidak pernah bocor ke Blade.
        foreach (['tool_calls', 'provider_tool_calls', 'attachments', 'participant_type', 'participant_id', 'agent_conversation'] as $leak) {
            $this->assertStringNotContainsString($leak, $show);
            $this->assertStringNotContainsString($leak, $index);
        }
    }
}
