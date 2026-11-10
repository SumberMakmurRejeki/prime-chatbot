<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_document_gemini_indexing_generations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('knowledge_document_id');
            $table->unsignedBigInteger('knowledge_document_gemini_indexing_id');
            // Penghapusan induk ditolak agar bukti resource Google tidak ikut hilang.
            $table->foreign('knowledge_document_id', 'kd_gemini_gen_doc_fk')->references('id')->on('knowledge_documents')->restrictOnDelete();
            $table->foreign('knowledge_document_gemini_indexing_id', 'kd_gemini_gen_index_fk')->references('id')->on('knowledge_document_gemini_indexings')->restrictOnDelete();
            $table->string('document_code', 64);
            $table->string('document_version', 64);
            $table->char('document_checksum', 64);
            $table->string('store_id', 190)->nullable();
            $table->string('google_file_id', 190)->nullable();
            $table->string('operation_name', 255)->nullable();
            $table->string('google_document_name', 255)->nullable();
            $table->string('state', 16);
            $table->unsignedInteger('generation');
            // Bukan FK: provenance tetap utuh meskipun revisi kredensial dihapus.
            $table->unsignedBigInteger('credential_configuration_id')->nullable();
            $table->unsignedBigInteger('credential_revision_id')->nullable();
            $table->string('import_identity', 64)->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->string('safe_error_message', 255)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('current_created_at')->nullable();
            $table->timestamp('current_updated_at')->nullable();
            // Waktu generation digantikan; satu-satunya timestamp milik baris history.
            $table->timestamp('archived_at');

            $table->unique(['knowledge_document_id', 'generation'], 'kd_gemini_generation_unique');
            $table->index('knowledge_document_gemini_indexing_id', 'kd_gemini_history_index_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_gemini_indexing_generations');
    }
};
