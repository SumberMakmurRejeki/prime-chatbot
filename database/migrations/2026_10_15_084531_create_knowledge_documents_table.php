<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_code', 64);
            $table->string('title', 200);
            $table->string('original_filename', 255);
            $table->string('version', 64);
            $table->char('checksum', 64)->unique('knowledge_documents_checksum_unique');
            // Lifecycle pemrosesan: uploaded | processing | ready | failed.
            $table->string('status', 16)->default('uploaded')->index('knowledge_documents_status_index');
            // Gerbang publikasi ditentukan Admin dan hanya berlaku setelah READY.
            $table->boolean('is_published')->default(false)->index('knowledge_documents_is_published_index');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            // Path storage privat ORIGINAL; publik tidak boleh mengakses berkas ini.
            $table->string('storage_path', 512);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('chunk_count')->nullable();
            // Provenance indexing untuk audit dan validasi ruang vektor saat publikasi.
            $table->string('vector_space_identity', 128)->nullable();
            $table->string('pipeline_fingerprint', 128)->nullable();
            $table->timestamp('processed_at')->nullable();
            // Diagnostik aman: tanpa path lokal, credential, atau payload provider mentah.
            $table->string('safe_error_code', 64)->nullable();
            $table->string('safe_error_message', 255)->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            // Satu kode dokumen hanya boleh punya satu versi aktif di registry.
            $table->unique(['document_code', 'version'], 'knowledge_documents_code_version_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
