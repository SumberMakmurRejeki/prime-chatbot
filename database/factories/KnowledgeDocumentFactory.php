<?php

namespace Database\Factories;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeDocument>
 */
class KnowledgeDocumentFactory extends Factory
{
    protected $model = KnowledgeDocument::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = 'FNC-'.Str::upper(Str::random(8));

        return [
            'document_code' => $code,
            'title' => fake()->sentence(4),
            'original_filename' => 'fnc-manual.pdf',
            'version' => '1.0',
            'checksum' => hash('sha256', Str::random(32)),
            'status' => 'ready',
            'is_published' => false,
            'storage_path' => 'knowledge-documents/'.$code.'/1.0/document.pdf',
            'file_size' => 1024,
            'page_count' => 1,
            'chunk_count' => 1,
            'vector_space_identity' => 'rag:config-1:revision-1:text-embedding-3-large:3072:cosine',
            'pipeline_fingerprint' => 'test-fingerprint',
            'processed_at' => now(),
            'uploaded_by' => User::factory(),
        ];
    }
}
