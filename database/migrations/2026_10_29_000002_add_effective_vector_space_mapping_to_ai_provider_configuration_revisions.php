<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pemetaan ruang vektor efektif Slice 2A: identitas ruang terindeks asli
     * yang boleh dipakai ulang sebuah revisi kandidat, plus bukti referensi
     * yang mengotorisasi pemetaan itu. Keduanya nullable dan ditulis sekali
     * setelah keputusan COMPATIBLE, sehingga revisi lama dan identitas
     * administratif yang sudah terindeks tidak berubah sama sekali.
     *
     * FK ke bukti referensi memakai restrictOnDelete: bukti bersifat write-once
     * dan pemetaan tidak boleh menggantung pada baris bukti yang sudah hilang.
     * Guard tetap memverifikasi sendiri agar integritas tidak bergantung hanya
     * pada constraint database.
     */
    public function up(): void
    {
        Schema::table('ai_provider_configuration_revisions', function (Blueprint $table): void {
            $table->string('effective_vector_space_identity', 128)->nullable()->after('tested_embedding_dimensions');
            $table->unsignedBigInteger('effective_vector_space_evidence_id')->nullable()->after('effective_vector_space_identity');
            $table->foreign('effective_vector_space_evidence_id', 'ai_provider_vector_space_evidence_fk')
                ->references('id')->on('ai_embedding_reference_evidences')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_configuration_revisions', function (Blueprint $table): void {
            $table->dropForeign('ai_provider_vector_space_evidence_fk');
            $table->dropColumn(['effective_vector_space_identity', 'effective_vector_space_evidence_id']);
        });
    }
};
