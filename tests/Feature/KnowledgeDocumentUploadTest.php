<?php

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_ai_manager_can_upload_pdf_and_queue_processing_with_identifiers_only(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $content = "%PDF-1.4\nSynthetic FNC-01 content\n";

        $this->actingAs($admin)->post(route('knowledge-documents.store'), [
            'title' => 'FNC-01 SOP',
            'version' => '1.0',
            'document' => UploadedFile::fake()->createWithContent('fnc-01-manual.pdf', $content),
        ])->assertRedirect(route('knowledge-documents.index'))
            ->assertSessionHas('flash_success', 'Dokumen berhasil diunggah dan masuk antrean pemrosesan.');

        $document = KnowledgeDocument::query()->sole();
        $this->assertSame('FNC-01-MANUAL', $document->document_code);
        $this->assertSame('FNC-01 SOP', $document->title);
        $this->assertSame('fnc-01-manual.pdf', $document->original_filename);
        $this->assertSame('1.0', $document->version);
        $this->assertSame('uploaded', $document->status);
        $this->assertFalse($document->is_published);
        $this->assertSame($admin->id, $document->uploaded_by);
        $this->assertSame(strlen($content), $document->file_size);
        $this->assertSame(hash('sha256', $content), $document->checksum);
        $this->assertStringStartsWith('knowledge-documents/FNC-01-MANUAL/1.0/', $document->storage_path);
        $this->assertStringEndsWith('.pdf', $document->storage_path);
        Storage::disk('local')->assertExists($document->storage_path);
        Queue::assertPushed(
            ProcessKnowledgeDocument::class,
            fn (ProcessKnowledgeDocument $job): bool => $job->documentId === $document->id,
        );
    }

    public function test_ordinary_admin_operator_and_guest_are_denied(): void
    {
        $ordinaryAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => false]);
        $this->actingAs($ordinaryAdmin)->get(route('knowledge-documents.index'))->assertForbidden();

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'manage_ai' => true]);
        $this->actingAs($operator)->get(route('knowledge-documents.index'))->assertRedirect(route('forbidden'));

        auth()->logout();
        $this->get(route('knowledge-documents.index'))->assertRedirect(route('login'));
    }

    public function test_access_is_enforced_server_side_not_only_in_the_sidebar(): void
    {
        Storage::fake('local');
        Queue::fake();
        $ordinaryAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => false]);

        $this->actingAs($ordinaryAdmin)->post(route('knowledge-documents.store'), [
            'title' => 'Tidak boleh',
            'version' => '1.0',
            'document' => UploadedFile::fake()->createWithContent('blocked.pdf', "%PDF-1.4\nx\n"),
        ])->assertForbidden();

        $this->assertSame(0, KnowledgeDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_publish_route_is_denied_for_operator_and_admin_without_manage_ai(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['gemini_file_search.indexing.store' => 'store-prime-dev']);
        $document = KnowledgeDocument::factory()->create(['status' => 'ready', 'is_published' => false]);
        $ordinaryAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => false]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'manage_ai' => true]);

        $this->actingAs($operator)
            ->post(route('knowledge-documents.publish', ['knowledgeDocument' => $document]))
            ->assertRedirect(route('forbidden'));

        $this->actingAs($ordinaryAdmin)
            ->post(route('knowledge-documents.publish', ['knowledgeDocument' => $document]))
            ->assertForbidden();

        auth()->logout();
        $this->post(route('knowledge-documents.publish', ['knowledgeDocument' => $document]))
            ->assertRedirect(route('login'));

        // Otorisasi ditegakkan di server: tidak ada yang berubah dari percobaan
        // yang ditolak, termasuk ketika direktorinya tidak diakses lebih dulu.
        $this->assertFalse($document->fresh()->is_published);
        $this->assertNull($document->fresh()->published_at);
    }

    public function test_non_pdf_and_oversized_upload_are_rejected(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $this->actingAs($admin);

        $this->post(route('knowledge-documents.store'), [
            'title' => 'Bukan PDF',
            'version' => '1.0',
            'document' => UploadedFile::fake()->createWithContent('manual.pdf', 'plain text, bukan pdf'),
        ])->assertSessionHasErrors('document');

        // Batas unggah 20 MB (20480 KB) berlaku sebelum berkas menyentuh storage privat.
        $this->post(route('knowledge-documents.store'), [
            'title' => 'Terlalu besar',
            'version' => '1.0',
            'document' => UploadedFile::fake()->createWithContent('oversized.pdf', "%PDF-1.4\n".str_repeat('x', 20_971_521)),
        ])->assertSessionHasErrors('document');

        $this->assertSame(0, KnowledgeDocument::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_duplicate_document_version_and_duplicate_content_are_rejected_without_orphan_files(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $this->actingAs($admin);
        $upload = fn (string $title, string $version, string $filename, string $content) => $this->post(route('knowledge-documents.store'), [
            'title' => $title,
            'version' => $version,
            'document' => UploadedFile::fake()->createWithContent($filename, $content),
        ]);

        $upload('FNC-01 SOP', '1.0', 'manual-pm.pdf', "%PDF-1.4\nkonten pertama\n")->assertRedirect();
        // Kombinasi kode+versi yang sama ditolak walau isi berbeda.
        $upload('FNC-01 SOP revisi', '1.0', 'manual-pm.pdf', "%PDF-1.4\nkonten kedua\n")->assertSessionHasErrors('version');
        // Konten identik di bawah kode/versi lain tetap ditolak sebagai duplikat konten.
        $upload('Dokumen lain', '2.0', 'other-manual.pdf', "%PDF-1.4\nkonten pertama\n")->assertSessionHasErrors('document');

        $document = KnowledgeDocument::query()->sole();
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertStringStartsWith('knowledge-documents/MANUAL-PM/1.0/', $document->storage_path);
        Queue::assertPushed(ProcessKnowledgeDocument::class, 1);
    }
}
