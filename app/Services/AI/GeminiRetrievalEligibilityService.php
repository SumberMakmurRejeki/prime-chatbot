<?php

namespace App\Services\AI;

use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;

/**
 * Kebijakan kelayakan sumber retrieval Gemini File Search (baca saja).
 *
 * Hanya keadaan MySQL dan konfigurasi Laravel yang menentukan kelayakan; kelas
 * ini tidak pernah menghubungi Google, tidak mengubah publikasi, tidak
 * mengindeks ulang, dan tidak menyimpan flag kelayakan baru yang menduplikasi
 * keadaan otoritatif. Karena itu kandidat mengikuti perubahan database apa
 * adanya: revisi yang digantikan langsung hilang dari daftar.
 *
 * Sebuah revisi hanya layak bila seluruh bukti berikut lengkap:
 * - Baris registry berstatus `ready`; status pipeline lain tidak pernah layak.
 * - Baris registry adalah revisi terpublikasi saat ini (`is_published`), bukan
 *   hasil pengurutan string versi. Publikasi ditentukan aksi Admin dan flag
 *   inilah satu-satunya penanda "revisi berlaku".
 * - Baris indeksasi Gemini ada, berstatus `completed`, dan memiliki token
 *   `import_identity` yang tersimpan.
 * - Provenance kredensial tersimpan: konfigurasi provider yang mengesahkan
 *   impor masih sama dengan konfigurasi provider chat aktif sekarang. Baris
 *   historis tanpa provenance tidak pernah dipercaya.
 * - Snapshot identitas pada baris indeksasi masih sama dengan baris registry
 *   (kode, versi, checksum), sehingga baris yang basi tidak pernah dipakai.
 * - Store indeksasi sama dengan store terkonfigurasi sekarang, dan nama
 *   resource dokumen berada di store itu.
 * - Ada tepat satu revisi chat Gemini yang eligible sebagai sumber kredensial,
 *   mengikuti kontrak {@see GeminiFileSearchConnection}.
 *
 * Kredensial aktif harus tepat sama dengan revisi yang mengesahkan impor;
 * inilah kontinuitas terverifikasi yang tersedia tanpa jaringan. Kredensial
 * baru (rotasi key) membuat kandidat tidak sah sampai revisi itu kembali
 * aktif ATAU alur verifikasi akses eksplisit ada; tanpa itu akses tidak dapat
 * diverifikasi dan wajib gagal tertutup. Recovery tidak pernah memaksa
 * reindex: store dan konfigurasi dibandingkan terpisah dari revisi kredensial.
 *
 * Predikat bukti indeks yang sama dipakai dua pemanggil dengan syarat yang
 * berbeda: retrieval menambahkan `status` ready dan `is_published`, sedangkan
 * gerbang publikasi memanggilnya untuk dokumen yang justru belum dipublikasikan
 * ({@see self::trustedIndexFor()}). Aturan provenance dengan demikian hidup di
 * satu tempat, bukan diduplikasi antar service.
 *
 * Setiap bukti yang hilang, ambigu, atau bertentangan menghasilkan kandidat
 * yang tidak dikembalikan (fail closed), bukan kandidat yang dilemahkan.
 * Kelayakan juga bukan aktivasi: pemanggil berikutnya tetap memutuskan sendiri
 * kapan jalur File Search dipakai.
 */
final class GeminiRetrievalEligibilityService
{
    public function __construct(
        private readonly GeminiFileSearchOperations $fileSearch,
        private readonly ChatProviderResolver $chatProviders,
    ) {}

    /**
     * Kandidat sumber yang sah menurut keadaan database saat ini.
     *
     * Hasil diurutkan menaik berdasarkan id registry agar urutan kandidat
     * stabil untuk pemanggil yang membangun batasan retrieval.
     *
     * @return Collection<int, GeminiRetrievalCandidate>
     */
    public function eligibleCandidates(): Collection
    {
        $authorized = $this->authorizedIndexContext();

        if ($authorized === null) {
            return collect();
        }

        return KnowledgeDocument::query()
            ->where('status', 'ready')
            ->where('is_published', true)
            ->with('geminiIndex')
            ->orderBy('id')
            ->get()
            ->map(fn (KnowledgeDocument $document): ?GeminiRetrievalCandidate => $this->candidateFor($document, $authorized))
            ->filter()
            ->values();
    }

    /**
     * Bukti indeks tepercaya untuk satu dokumen, TANPA mensyaratkan dokumen
     * sudah dipublikasikan.
     *
     * Gerbang publikasi memakai predikat ini supaya aturan provenance tidak
     * hidup di dua tempat. NULL berarti publishability belum terbukti dan
     * pemanggil wajib gagal tertutup: store atau kredensial Gemini belum
     * lengkap, baris indeks belum `completed`, snapshot identitas sudah basi,
     * store berbeda, atau provenance kredensial/import identity tidak ada.
     */
    public function trustedIndexFor(KnowledgeDocument $document): ?GeminiRetrievalCandidate
    {
        $authorized = $this->authorizedIndexContext();

        return $authorized === null ? null : $this->candidateFor($document, $authorized);
    }

    /**
     * Konteks kredensial dan store yang mengesahkan indeks; NULL bila store
     * terkonfigurasi atau revisi Gemini tunggal yang eligible tidak tersedia.
     *
     * @return array{store: string, configurationId: int, revisionId: int}|null
     */
    private function authorizedIndexContext(): ?array
    {
        $store = $this->fileSearch->configuredStoreIdentity();
        $revision = $this->activeGeminiRevision();

        if ($store === null || $revision === null) {
            return null;
        }

        return [
            'store' => $store,
            'configurationId' => (int) $revision->configuration_id,
            'revisionId' => (int) $revision->id,
        ];
    }

    /**
     * @param  array{store: string, configurationId: int, revisionId: int}  $authorized
     */
    private function candidateFor(KnowledgeDocument $document, array $authorized): ?GeminiRetrievalCandidate
    {
        $index = $document->geminiIndex;

        if ($index === null || $index->state !== GeminiDocumentIndexState::Completed) {
            return null;
        }

        if (! $this->indexSnapshotMatches($index, $document)) {
            return null;
        }

        if ($index->store_id !== $authorized['store']) {
            return null;
        }

        if (! $this->credentialProvenanceMatches($index, $authorized['configurationId'], $authorized['revisionId'])) {
            return null;
        }

        return $this->candidateFromVerifiedIndex($index, $authorized['store'], $authorized['configurationId']);
    }

    /**
     * Snapshot indeksasi harus masih menggambarkan baris registry yang sama:
     * versi atau checksum yang berubah menandakan dokumen sudah digantikan
     * sehingga hasil indeksasi lama tidak boleh dipercaya.
     */
    private function indexSnapshotMatches(KnowledgeDocumentGeminiIndex $index, KnowledgeDocument $document): bool
    {
        return $index->document_code === $document->document_code
            && $index->document_version === $document->version
            && is_string($index->document_checksum)
            && hash_equals($index->document_checksum, (string) $document->checksum);
    }

    /**
     * Nama resource Google harus berada tepat di bawah store terkonfigurasi dan
     * memuat id dokumen yang tidak kosong, sehingga identitas lintas store atau
     * identitas tanpa dokumen tidak pernah lolos sebagai kandidat.
     */
    private function candidateFromVerifiedIndex(KnowledgeDocumentGeminiIndex $index, string $store, int $authorizedConfigurationId): ?GeminiRetrievalCandidate
    {
        $requiredPrefix = $store.'/documents/';
        $documentName = $index->google_document_name;

        if (! is_string($documentName) || ! str_starts_with($documentName, $requiredPrefix)) {
            return null;
        }

        $googleDocumentId = substr($documentName, strlen($requiredPrefix));

        if ($googleDocumentId === '' || str_contains($googleDocumentId, '/')) {
            return null;
        }

        return new GeminiRetrievalCandidate(
            documentCode: $index->document_code,
            version: $index->document_version,
            storeName: $store,
            googleDocumentName: $documentName,
            importIdentity: $index->import_identity,
            credentialConfigurationId: $authorizedConfigurationId,
            credentialRevisionId: $index->credential_revision_id,
        );
    }

    /**
     * Tidak ada introspeksi akses Store tanpa jaringan, jadi hanya revisi yang
     * sama dengan pencatat impor yang dipercaya; key baru memerlukan
     * verifikasi eksplisit sebelum boleh memakai SOP lama.
     *
     * Ketepatan revisi ini bukan bukti kepemilikan dokumen di Google; ia hanya
     * memastikan kredensial yang akan mengirim permintaan adalah kredensial
     * yang sudah terbukti mengesahkan impor.
     */
    private function activeGeminiRevision(): ?AiProviderConfigurationRevision
    {
        $revisions = $this->chatProviders->eligibleRevisions();
        $revision = $revisions->count() === 1 ? $revisions->first() : null;

        return $revision !== null && Lab::tryFrom((string) $revision->driver) === Lab::Gemini
            ? $revision
            : null;
    }

    private function credentialProvenanceMatches(KnowledgeDocumentGeminiIndex $index, int $authorizedConfigurationId, int $authorizedRevisionId): bool
    {
        return $index->credential_configuration_id === $authorizedConfigurationId
            && $index->credential_revision_id === $authorizedRevisionId
            && is_string($index->import_identity)
            && $index->import_identity !== '';
    }
}
