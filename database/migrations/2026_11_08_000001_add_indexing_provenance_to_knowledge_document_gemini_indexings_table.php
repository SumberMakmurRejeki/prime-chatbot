<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance kredensial dan identitas impor unik pada baris indeksasi Gemini.
 *
 * Kolom ini bersifat tambahan dan nullable agar baris historis tetap ada;
 * baris tanpa provenance atau tanpa import identity tidak boleh dipercaya
 * untuk retrieval (fail closed), dan tidak ada backfill otomatis.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_document_gemini_indexings', function (Blueprint $table): void {
            // Identitas non-secret dari revisi provider konfigurasi yang
            // mengesahkan (mengunggah/mengimpor) dokumen ke store. Bukan FK:
            // penghapusan revisi provider tidak boleh menghapus bukti ini.
            $table->unsignedBigInteger('credential_configuration_id')->nullable();
            $table->unsignedBigInteger('credential_revision_id')->nullable();
            // Token acak non-secret per generasi impor. Satu token per
            // (baris, generation); unik agar dua impor terpisah tidak dapat
            // tertukar pada filter metadata retrieval.
            $table->string('import_identity', 64)->nullable()->unique('knowledge_document_gemini_indexings_import_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_document_gemini_indexings', function (Blueprint $table): void {
            $table->dropUnique('knowledge_document_gemini_indexings_import_identity_unique');
            $table->dropColumn(['credential_configuration_id', 'credential_revision_id', 'import_identity']);
        });
    }
};
