<?php

namespace Tests\Feature;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeDocumentStatusUiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Label status harus menjelaskan keadaan antrean, bukan istilah teknis seperti
     * READY/DIUNGGAH, supaya Admin tidak menebak arti status dokumen.
     */
    public function test_each_lifecycle_state_renders_a_human_readable_label_and_next_action(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $publisher = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);

        KnowledgeDocument::factory()->create(['status' => 'uploaded', 'is_published' => false]);
        KnowledgeDocument::factory()->create(['status' => 'processing', 'is_published' => false]);
        $readyUnpublished = KnowledgeDocument::factory()->create(['status' => 'ready', 'is_published' => false]);
        $readyPublished = KnowledgeDocument::factory()->create([
            'status' => 'ready',
            'is_published' => true,
            'published_at' => now(),
            'published_by' => $publisher->id,
        ]);
        $failed = KnowledgeDocument::factory()->create([
            'status' => 'failed',
            'is_published' => false,
            'safe_error_code' => 'internal-error-code',
            'safe_error_message' => 'Ekstraksi teks gagal.',
        ]);

        $response = $this->actingAs($manager)->get(route('knowledge-documents.index'));

        $response->assertOk()
            ->assertSee('Menunggu antrean')
            ->assertSee('Sedang diproses')
            ->assertSee('Siap dipublikasikan')
            ->assertSee('Gagal diproses')
            ->assertSee('Selesai diproses')
            ->assertSee('Aktif di Knowledge Base')
            ->assertSee('Belum dipublikasikan')
            ->assertSee('Ekstraksi teks gagal.')
            ->assertSee('Unggah dokumen')
            ->assertDontSee('internal-error-code');

        // Hanya dokumen READY yang belum dipublikasikan boleh menampilkan aksi publikasi.
        $response->assertSee(route('knowledge-documents.publish', $readyUnpublished));
        $response->assertDontSee(route('knowledge-documents.publish', $readyPublished));

        // Hanya dokumen GAGAL yang boleh menampilkan aksi coba lagi.
        $response->assertSee(route('knowledge-documents.retry', $failed));
        $response->assertSee('Coba Lagi');
    }
}
