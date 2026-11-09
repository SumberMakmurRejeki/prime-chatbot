<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Services\AI\GroundedAnswerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $answers = $this->createStub(GroundedAnswerService::class);
        $answers->method('answer')->willReturn(['answer' => 'Jawaban uji.', 'citations' => []]);
        $this->app->instance(GroundedAnswerService::class, $answers);
    }

    public function test_only_active_admins_can_access_the_assistant(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'is_active' => false]);

        $this->get('/assistant')->assertRedirect('/login');
        $this->actingAs($operator)->get('/assistant')->assertRedirect('/403');
        $this->actingAs($admin)->get('/assistant')->assertOk();
        $this->actingAs($manager)->get('/assistant')->assertOk();
        $this->actingAs($inactiveAdmin)->get('/assistant')->assertRedirect('/403');
    }

    public function test_admin_can_send_own_escaped_messages_manage_ai_cannot_access_others_and_operator_is_rejected(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $answers = $this->createMock(GroundedAnswerService::class);
        $answers->expects($this->once())->method('answer')->willReturn(['answer' => 'Jawaban uji.', 'citations' => []]);
        $this->app->instance(GroundedAnswerService::class, $answers);
        $created = $this->actingAs($owner)->post('/assistant/conversations', []);
        $created->assertRedirect();
        $id = basename($created->headers->get('Location'));

        // Pesan user tersimpan dan konten html di-escape; tidak ada request provider nyata.
        $this->post("/assistant/conversations/{$id}/messages", [
            'message' => '<script>alert(1)</script>', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect("/assistant/conversations/{$id}");

        $this->actingAs($owner)->get("/assistant/conversations/{$id}")
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        // Daftar sidebar hanya menampilkan judul percakapan milik owner sendiri.
        $this->actingAs($owner)->get('/assistant')->assertOk()->assertSee('alert(1)');

        // manage_ai bukan otorisasi percakapan: ID yang ditebak tetap 404.
        $this->actingAs($other)->get("/assistant/conversations/{$id}")->assertNotFound();
        $this->actingAs($other)->post("/assistant/conversations/{$id}/messages", [
            'message' => 'intrusion', 'request_id' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->actingAs($other)->delete("/assistant/conversations/{$id}")->assertNotFound();
        $this->actingAs($other)->get('/assistant')->assertOk()->assertDontSee('Percakapan baru');
    }

    public function test_operator_and_inactive_admin_are_rejected_from_message_and_delete_routes(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($owner)->post('/assistant/conversations', [])->assertRedirect();
        $id = basename((string) $this->post('/assistant/conversations', [])->headers->get('Location'));

        foreach ([User::factory()->create(['role' => 'operator', 'is_active' => true]), User::factory()->create(['role' => 'admin', 'is_active' => false])] as $actor) {
            $this->actingAs($actor)->post("/assistant/conversations/{$id}/messages", [
                'message' => 'x', 'request_id' => (string) Str::uuid(),
            ])->assertRedirect('/403');
            $this->actingAs($actor)->delete("/assistant/conversations/{$id}")->assertRedirect('/403');
        }

        $this->assertDatabaseCount('agent_conversation_messages', 0);
    }

    public function test_owner_can_delete_conversation_with_cascade_and_retry_of_same_request_id(): void
    {
        $answers = $this->createStub(GroundedAnswerService::class);
        $answers->method('answer')->willReturn(['answer' => 'Jawaban uji.', 'citations' => []]);
        $this->app->instance(GroundedAnswerService::class, $answers);
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($owner)->post('/assistant/conversations', [])->assertRedirect();
        $id = basename((string) $this->post('/assistant/conversations', [])->headers->get('Location'));
        $requestId = (string) Str::uuid();
        $this->post("/assistant/conversations/{$id}/messages", [
            'message' => 'Data sebelum dihapus', 'request_id' => $requestId,
        ])->assertRedirect();

        $this->actingAs($owner)->delete("/assistant/conversations/{$id}")
            ->assertRedirect('/assistant')
            ->assertSessionHas('flash_success', 'Percakapan dihapus.');
        $this->assertDatabaseMissing('agent_conversations', ['id' => $id]);
        $this->assertDatabaseCount('agent_conversation_messages', 0);

        // Percakapan yang sudah dihapus tidak bisa dipakai lagi, termasuk replay request id yang sama.
        $this->actingAs($owner)->post("/assistant/conversations/{$id}/messages", [
            'message' => 'replay', 'request_id' => $requestId,
        ])->assertNotFound();
    }

    public function test_user_with_conversation_cannot_be_hard_deleted_but_can_be_deactivated(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $created = $this->actingAs($owner)->post('/assistant/conversations', []);
        $created->assertRedirect();
        $conversationId = basename($created->headers->get('Location'));
        $this->post("/assistant/conversations/{$conversationId}/messages", [
            'message' => 'Riwayat harus utuh', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $ownerId = $owner->id;

        // Penolakan hapus: pesan domain aman, tanpa partial deletion.
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))
            ->delete('/settings/users/'.$ownerId)
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $ownerId, 'deleted_at' => null]);
        $this->assertDatabaseHas('agent_conversations', ['id' => $conversationId, 'participant_id' => $ownerId]);
        $this->assertDatabaseHas('agent_conversation_messages', [
            'conversation_id' => $conversationId,
            'content' => 'Riwayat harus utuh',
        ]);

        // Deaktivasi tetap diizinkan dan tidak mengubah riwayat percakapan.
        $this->patch('/settings/users/'.$ownerId.'/nonaktifkan')->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $ownerId, 'is_active' => false]);
        $this->assertDatabaseHas('agent_conversation_messages', [
            'conversation_id' => $conversationId,
            'content' => 'Riwayat harus utuh',
        ]);
    }

    public function test_reopening_with_malformed_cursor_shows_first_page_instead_of_error(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($owner)->post('/assistant/conversations', [])->assertRedirect();
        $id = basename((string) $this->post('/assistant/conversations', [])->headers->get('Location'));

        $this->get("/assistant/conversations/{$id}?cursor=not-a-valid-cursor")->assertOk();
        $this->get('/assistant/conversations/'.$id.'?cursor[]=x')->assertOk();
    }

    public function test_message_length_is_bounded_and_unknown_ids_fail_as_not_found(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($owner)->get('/assistant/conversations/not-a-uuid')->assertNotFound();
        $this->post('/assistant/conversations/not-a-uuid/messages', ['message' => 'hello', 'request_id' => (string) Str::uuid()])->assertNotFound();

        $this->post('/assistant/conversations', [])->assertRedirect();
        $id = basename((string) $this->post('/assistant/conversations', [])->headers->get('Location'));
        $this->post("/assistant/conversations/{$id}/messages", ['message' => str_repeat('x', 10001), 'request_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('message');
    }
}
