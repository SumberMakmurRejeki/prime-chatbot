<?php

namespace Tests\Feature;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeDocumentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_manager_receives_safe_status_for_the_requested_page(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $states = [
            ['status' => 'uploaded', 'is_published' => false],
            ['status' => 'processing', 'is_published' => false],
            ['status' => 'ready', 'is_published' => false],
            ['status' => 'ready', 'is_published' => true],
            ['status' => 'failed', 'is_published' => false, 'safe_error_message' => 'Pemrosesan gagal.'],
        ];

        foreach ($states as $state) {
            KnowledgeDocument::factory()->create(array_merge([
                'title' => 'Panduan teknis',
                'checksum' => hash('sha256', $state['status'].'-'.(int) $state['is_published']),
                'storage_path' => 'private/internal/path.pdf',
                'original_filename' => 'private.pdf',
                'safe_error_code' => 'internal-error-code',
                'file_size' => 999,
            ], $state));
        }

        $response = $this->actingAs($manager)->getJson(route('knowledge-documents.status', ['page' => 1]))
            ->assertOk()
            ->assertJsonPath('has_active_processing', true)
            ->assertJsonCount(5, 'documents');

        $payload = $response->json('documents');
        $byStatusAndPublished = collect($payload)->keyBy(fn (array $document): string => $document['status'].'-'.(int) $document['is_published']);

        $this->assertSame(['id', 'title', 'version', 'status', 'is_published', 'safe_error_message', 'created_at', 'publish_url', 'retry_url'], array_keys($payload[0]));
        $this->assertSame('uploaded', $byStatusAndPublished['uploaded-0']['status']);
        $this->assertFalse($byStatusAndPublished['uploaded-0']['is_published']);
        $this->assertNull($byStatusAndPublished['uploaded-0']['publish_url']);
        $this->assertNull($byStatusAndPublished['uploaded-0']['retry_url']);
        $this->assertSame('processing', $byStatusAndPublished['processing-0']['status']);
        $this->assertNotNull($byStatusAndPublished['ready-0']['publish_url']);
        $this->assertNull($byStatusAndPublished['ready-0']['retry_url']);
        $this->assertNull($byStatusAndPublished['ready-1']['publish_url']);
        $this->assertNotNull($byStatusAndPublished['failed-0']['retry_url']);
        $this->assertSame('Pemrosesan gagal.', $byStatusAndPublished['failed-0']['safe_error_message']);
        $this->assertStringNotContainsString('secret-checksum', $response->getContent());
        $this->assertStringNotContainsString('private/internal/path.pdf', $response->getContent());
        $this->assertStringNotContainsString('private.pdf', $response->getContent());
        $this->assertStringNotContainsString('internal-error-code', $response->getContent());
    }

    public function test_active_processing_flag_only_considers_the_selected_page(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        KnowledgeDocument::factory()->count(20)->create([
            'status' => 'ready',
            'is_published' => true,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        KnowledgeDocument::factory()->create(['status' => 'processing', 'is_published' => false, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

        $this->actingAs($manager)->getJson(route('knowledge-documents.status', ['page' => 2]))
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.status', 'processing')
            ->assertJsonPath('has_active_processing', true);

        // Halaman 1 tidak memuat baris processing, jadi flag tidak boleh aktif hanya karena halaman lain masih memproses.
        $this->actingAs($manager)->getJson(route('knowledge-documents.status', ['page' => 1]))
            ->assertOk()
            ->assertJsonPath('has_active_processing', false);

        KnowledgeDocument::query()->where('status', 'processing')->delete();

        $this->actingAs($manager)->getJson(route('knowledge-documents.status', ['page' => 2]))
            ->assertOk()
            ->assertJsonCount(0, 'documents')
            ->assertJsonPath('has_active_processing', false);
    }

    public function test_status_endpoint_denies_non_manager_admin_operator_and_guest(): void
    {
        $ordinaryAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => false]);
        $this->actingAs($ordinaryAdmin)->getJson(route('knowledge-documents.status'))->assertForbidden();

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'manage_ai' => true]);
        $this->actingAs($operator)->get(route('knowledge-documents.status'))->assertRedirect(route('forbidden'));

        auth()->logout();
        $this->get(route('knowledge-documents.status'))->assertRedirect(route('login'));
    }
}
