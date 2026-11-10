<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti per generasi apakah satu mutasi Google mungkin sudah dimulai.
 *
 * Kolom nullable dan tanpa backfill: baris lama tetap NULL karena error code
 * dan timestamp yang ada tidak pernah membuktikan keamanan unggahan/impor
 * historis. NULL harus dibaca sebagai "tidak diketahui", bukan "belum dimulai".
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_document_gemini_indexings', function (Blueprint $table): void {
            // not_started|may_have_started. NULL hanya untuk baris historis.
            $table->string('remote_write_evidence', 16)->nullable()->after('generation');
        });

        Schema::table('knowledge_document_gemini_indexing_generations', function (Blueprint $table): void {
            // Snapshot arsip memakai kolom yang sama agar bukti lama tidak hilang.
            $table->string('remote_write_evidence', 16)->nullable()->after('generation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_document_gemini_indexing_generations', function (Blueprint $table): void {
            $table->dropColumn('remote_write_evidence');
        });

        Schema::table('knowledge_document_gemini_indexings', function (Blueprint $table): void {
            $table->dropColumn('remote_write_evidence');
        });
    }
};
