<?php

namespace App\Services\AI;

/**
 * Identitas minimal satu SOP yang boleh menjadi sumber retrieval Gemini.
 *
 * Kandidat ini hanya berisi identitas yang sudah diverifikasi terhadap keadaan
 * MySQL dan sengaja tidak berisi string filter provider: permintaan retrieval
 * berikutnya wajib menyusun sendiri batasan store dan metadata dari nilai-nilai
 * ini, bukan dari asumsi. Kandidat adalah bukti kelayakan saat evaluasi
 * berjalan, bukan otorisasi permanen; begitu flag publikasi berpindah kandidat
 * ini tidak lagi berlaku.
 *
 * `storeName` adalah store tujuan terkonfigurasi, `googleDocumentName` adalah
 * resource dokumen pada store yang sama, dan `importIdentity` adalah token
 * generasi impor yang tercatat pada baris indeksasi. Provenance kredensial
 * dipisahkan menjadi dua nilai: `credentialConfigurationId` adalah slot
 * konfigurasi provider yang berwenang, sedangkan `credentialRevisionId` adalah
 * revisi kredensial yang benar-benar mengesahkan impor. Kesamaan revisi bukan
 * bukti kepemilikan dokumen di sisi Google; ia hanya bukti kredensial mana yang
 * menulis dokumen itu.
 */
final readonly class GeminiRetrievalCandidate
{
    public function __construct(
        public string $documentCode,
        public string $version,
        public string $storeName,
        public string $googleDocumentName,
        public string $importIdentity,
        public int $credentialConfigurationId,
        public int $credentialRevisionId,
    ) {}
}
