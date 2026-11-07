<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas Google dan status indeksasi Gemini File Search per dokumen.
 *
 * Dipisahkan dari `knowledge_documents` karena `status` di tabel itu milik
 * pipeline Qdrant legacy (uploaded|processing|ready|failed) dan tidak boleh
 * ditumpangi status provider lain. Satu baris per dokumen versi registry;
 * kegagalan Gemini tidak mengubah lifecycle legacy.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_document_gemini_indexings', function (Blueprint $table): void {
            $table->id();
            // Satu baris indeksasi per baris registry. Replacement dokumen
            // menghasilkan baris registry baru, bukan baris kedua di sini.
            $table->foreignId('knowledge_document_id')->unique()->constrained('knowledge_documents', indexName: 'kd_gemini_doc_fk')->cascadeOnDelete();
            // Snapshot identitas saat dijadwalkan; job basi ditolak bila versi
            // atau checksum baris registry tidak lagi sama.
            $table->string('document_code', 64);
            $table->string('document_version', 64);
            $table->char('document_checksum', 64);
            // NULL hanya bila konfigurasi belum menetapkan store saat dibuat.
            $table->string('store_id', 190)->nullable();
            // Identitas resource Google yang diketahui; NULL berarti belum ada.
            $table->string('google_file_id', 190)->nullable();
            $table->string('operation_name', 255)->nullable();
            $table->string('google_document_name', 255)->nullable();
            // queued|uploading|uploaded|importing|pending|completed|failed|unknown.
            $table->string('state', 16)->default('queued');
            // Naik setiap percobaan baru; job dengan generation lama dianggap basi.
            $table->unsignedInteger('generation')->default(1);
            // Diagnostik aman: tanpa path lokal, credential, atau payload provider mentah.
            $table->string('safe_error_code', 64)->nullable();
            $table->string('safe_error_message', 255)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_gemini_indexings');
    }
};
