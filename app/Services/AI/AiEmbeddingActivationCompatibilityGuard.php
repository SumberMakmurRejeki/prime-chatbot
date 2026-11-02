<?php

namespace App\Services\AI;

use App\Models\AiEmbeddingReferenceEvidence;
use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Services\AI\VectorStore\DistanceMetric;

/**
 * Batas keputusan tunggal pemakaian ulang ruang vektor untuk Activate/Restore.
 *
 * Guard ini murni keputusan: tidak memindahkan pointer provider, tidak memanggil
 * Qdrant, dan tidak membuktikan keadaan kosong SQL/Qdrant. Klasifikasi keadaan
 * indeks datang dari pemanggil (EmbeddingIndexState) dan bukan bukti; cabang
 * PROVEN_EMPTY hanya menghasilkan keputusan bootstrap yang tetap menuntut
 * pembuktian dan otorisasi terpisah, bukan aktivasi.
 *
 * Identitas ruang efektif dibaca dari pemetaan durable pada revisi kandidat dan
 * divalidasi terhadap identitas yang diturunkan dari bukti referensi tersegel
 * untuk provenance kandidat itu sendiri. Selain itu identitas tersebut wajib
 * diakui metadata indexing (knowledge_documents.vector_space_identity yang hanya
 * diisi setelah upsert Qdrant berhasil); identitas yang hanya bisa
 * direkonstruksi dari bukti tidak pernah menjadi bukti ruang terindeks.
 * Identitas administratif kandidat tidak diubah sama sekali, sehingga identitas
 * titik Qdrant lama tetap byte-per-byte.
 */
final class AiEmbeddingActivationCompatibilityGuard
{
    public function __construct(private readonly AiEmbeddingCompatibilityEvaluator $evaluator) {}

    /**
     * Keputusan untuk kandidat Activate maupun Restore; satu jalur, satu aturan.
     */
    public function decide(AiProviderConfigurationRevision $candidate, EmbeddingIndexState $indexState): EmbeddingSpaceDecision
    {
        if ($indexState === EmbeddingIndexState::Ambiguous) {
            return EmbeddingSpaceDecision::reject('index_state_ambiguous', 'Knowledge or vector-index state is unknown or inconsistent.');
        }

        if ($indexState === EmbeddingIndexState::ProvenEmpty) {
            if ($candidate->effective_vector_space_identity !== null) {
                // Keadaan kosong yang diklaim tidak boleh dipakai untuk menimpa
                // riwayat indexing; dua sumber keadaan yang bertentangan ditolak.
                return EmbeddingSpaceDecision::reject('index_state_ambiguous', 'A proven-empty index state contradicts a recorded effective-space mapping.');
            }

            return EmbeddingSpaceDecision::bootstrapRequired('No indexed vector space exists; a separate empty-state proof and bootstrap authorization are still required.');
        }

        $reference = $this->evidenceFor($candidate);
        $mapping = $this->evaluateOffline($candidate, $reference);

        if ($mapping->status !== EmbeddingSpaceDecisionStatus::ReuseIndexedSpace || $reference === null) {
            return $mapping;
        }

        $compatibility = $this->evaluator->evaluate($candidate, $reference);

        if ($compatibility->status !== EmbeddingCompatibilityStatus::Compatible) {
            // UNKNOWN dan INCOMPATIBLE sama-sama menolak pemakaian ulang; kode
            // evaluator diteruskan apa adanya agar stabil dan dapat diaudit.
            return EmbeddingSpaceDecision::reject($compatibility->reasonCode, $compatibility->detail);
        }

        return EmbeddingSpaceDecision::reuse(
            'probe_match_verified_provenance',
            (string) $mapping->effectiveSpaceIdentity,
            $compatibility->detail,
        );
    }

    /**
     * Verifikasi struktural pemetaan tersimpan tanpa I/O provider; dipakai jalur
     * retrieval/aktivasi berikutnya yang tidak perlu mengulang probe.
     */
    public function verifyEffectiveSpaceMapping(AiProviderConfigurationRevision $candidate): EmbeddingSpaceDecision
    {
        return $this->evaluateOffline($candidate, $this->evidenceFor($candidate));
    }

    /**
     * Menetapkan pemetaan ruang efektif sekali saja dari bukti tersegel.
     *
     * Pemetaan hanya ditulis setelah identitas terbukti terindeks (attestasi
     * metadata indexing) dan evaluator menyatakan kandidat COMPATIBLE, jadi ruang
     * lama tidak pernah dipakai ulang hanya karena administrasi.
     * Pemetaan identik bersifat idempoten, pemetaan berbeda ditolak, dan UPDATE
     * bersyarat `whereNull` membuat dua penulis bersamaan tidak saling menimpa.
     */
    public function recordEffectiveSpaceMapping(AiProviderConfigurationRevision $candidate): EmbeddingSpaceDecision
    {
        $reference = $this->evidenceFor($candidate);
        $failure = $this->referenceFailure($reference);

        if ($failure !== null) {
            return $failure;
        }

        $derivedIdentity = $this->derivedIdentity($reference);

        if ($derivedIdentity === null || $reference === null) {
            return EmbeddingSpaceDecision::reject('indexed_space_unknown', 'Reference evidence has no source revision for the indexed-space identity.');
        }

        $existing = $this->evaluateOffline($candidate, $reference);

        if ($existing->status === EmbeddingSpaceDecisionStatus::ReuseIndexedSpace
            || $existing->reasonCode !== 'effective_space_unmapped') {
            return $existing;
        }

        if (! $this->indexedSpaceAttested($derivedIdentity)) {
            return EmbeddingSpaceDecision::reject('indexed_space_unattested', 'No trusted indexing metadata attests vectors written in the recorded effective space.');
        }

        $compatibility = $this->evaluator->evaluate($candidate, $reference);

        if ($compatibility->status !== EmbeddingCompatibilityStatus::Compatible) {
            return EmbeddingSpaceDecision::reject($compatibility->reasonCode, $compatibility->detail);
        }

        AiProviderConfigurationRevision::query()
            ->whereKey($candidate->getKey())
            ->whereNull('effective_vector_space_identity')
            ->whereNull('effective_vector_space_evidence_id')
            ->update([
                'effective_vector_space_identity' => $derivedIdentity,
                'effective_vector_space_evidence_id' => $reference->id,
            ]);

        $candidate->refresh();

        return $this->verifyEffectiveSpaceMapping($candidate);
    }

    /**
     * Identitas ruang terindeks diakui bila metadata indexing aplikasi benar-benar
     * menyatakan ruang itu pernah dipakai untuk menulis vektor.
     *
     * knowledge_documents.vector_space_identity diisi hanya setelah upsert Qdrant
     * berhasil dan divalidasi ulang saat publikasi. Kecocokan identitas di sini
     * mengikat bukti ke revisi yang tercatat menulis vektor, bukan sekadar string
     * yang bisa direkonstruksi. Dokumen yang tidak READY belum terbukti
     * tersimpan, jadi tidak mengakui apa pun.
     */
    private function indexedSpaceAttested(string $identity): bool
    {
        return KnowledgeDocument::query()
            ->where('status', 'ready')
            ->where('vector_space_identity', $identity)
            ->exists();
    }

    /** Pemeriksaan deterministik tanpa I/O provider. */
    private function evaluateOffline(AiProviderConfigurationRevision $candidate, ?AiEmbeddingReferenceEvidence $reference): EmbeddingSpaceDecision
    {
        $failure = $this->referenceFailure($reference);

        if ($failure !== null) {
            return $failure;
        }

        if ($reference === null) {
            return EmbeddingSpaceDecision::reject('reference_missing', 'No trusted reference evidence exists for the candidate provenance.');
        }

        $identity = $candidate->effective_vector_space_identity;
        $evidenceId = $candidate->effective_vector_space_evidence_id;

        if ($identity === null || $identity === '' || $evidenceId === null) {
            return EmbeddingSpaceDecision::reject('effective_space_unmapped', 'The revision has no durable effective-space mapping.');
        }

        if ((int) $evidenceId !== (int) $reference->id) {
            return EmbeddingSpaceDecision::reject('evidence_mismatch', 'The recorded mapping cites evidence other than the candidate provenance evidence.');
        }

        $derivedIdentity = $this->derivedIdentity($reference);

        if ($derivedIdentity === null) {
            return EmbeddingSpaceDecision::reject('indexed_space_unknown', 'Reference evidence has no source revision for the indexed-space identity.');
        }

        if ($identity !== $derivedIdentity) {
            return EmbeddingSpaceDecision::reject('indexed_space_mismatch', 'Recorded mapping differs from the original indexed-space identity.');
        }

        // Identitas yang cocok dengan bukti masih belum membuktikan ada vektor
        // yang ditulis pada ruang itu: metadata indexing (vector_space_identity
        // yang baru diisi setelah upsert Qdrant berhasil) adalah satu-satunya
        // catatan aplikasi yang mengaitkan keduanya. Tanpa perlindungan ini,
        // bukti dari satu revisi dapat dianggap otorisasi untuk ruang yang
        // tidak pernah terindeks sama sekali.
        if (! $this->indexedSpaceAttested($identity)) {
            return EmbeddingSpaceDecision::reject('indexed_space_unattested', 'No trusted indexing metadata attests vectors written in the recorded effective space.');
        }

        return EmbeddingSpaceDecision::reuse('effective_space_mapping_verified', $identity, 'Durable effective-space mapping verified against sealed reference evidence.');
    }

    /**
     * Bukti dikunci per fingerprint provenance + profil probe. UNIQUE reference
     * fingerprint menjamin paling banyak satu baris, sehingga pilihan bukti tidak
     * pernah ambigu; bukti dari profil probe lain dianggap tidak sebanding dan
     * diperlakukan seperti bukti hilang.
     */
    private function evidenceFor(AiProviderConfigurationRevision $candidate): ?AiEmbeddingReferenceEvidence
    {
        return AiEmbeddingReferenceEvidence::query()
            ->where('provenance_fingerprint', EmbeddingProvenance::forRevision($candidate)->fingerprint())
            ->where('probe_profile', EmbeddingProbeProfile::PROFILE)
            ->where('probe_profile_version', EmbeddingProbeProfile::VERSION)
            ->first();
    }

    private function referenceFailure(?AiEmbeddingReferenceEvidence $reference): ?EmbeddingSpaceDecision
    {
        if ($reference === null) {
            return EmbeddingSpaceDecision::reject('reference_missing', 'No trusted reference evidence exists for the candidate provenance.');
        }

        if (! $reference->isIntegrityValid()) {
            return EmbeddingSpaceDecision::reject('reference_untrusted', 'Persisted reference evidence failed integrity verification.');
        }

        if ((string) $reference->distance_metric !== DistanceMetric::Cosine->value) {
            // Identitas ruang saat ini hanya mengenal metrik cosine; metrik lain
            // tidak dapat direkonstruksi sehingga wajib gagal tertutup.
            return EmbeddingSpaceDecision::reject('distance_metric_unsupported', 'Reference evidence uses an unsupported distance metric.');
        }

        return null;
    }

    /** Identitas ruang asli yang diotorisasi oleh bukti; null bila komponen sumber tidak lengkap. */
    private function derivedIdentity(?AiEmbeddingReferenceEvidence $reference): ?string
    {
        if ($reference === null || $reference->source_configuration_id === null || $reference->source_revision_id === null) {
            return null;
        }

        return EmbeddingRevisionResolver::identityFromComponents(
            (int) $reference->source_configuration_id,
            (int) $reference->source_revision_id,
            (string) $reference->model_id,
            (int) $reference->dimension,
        );
    }
}
