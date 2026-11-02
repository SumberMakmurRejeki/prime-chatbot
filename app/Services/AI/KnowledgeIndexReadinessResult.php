<?php

namespace App\Services\AI;

/**
 * Hasil penilaian read-only kesiapan pengetahuan/indeks (Slice 2B-1).
 *
 * Hasil ini adalah BUKTI PENGAMATAN, bukan izin aktivasi. Ia tidak mengunci,
 * tidak memesan, dan tidak menjadikan lingkungan atomik: keadaan dapat berubah
 * setelah pengamatan selesai, termasuk oleh ingestion yang dimulai setelahnya.
 *
 * Hubungan dengan guard Slice 2A: `state` dipakai apa adanya sebagai
 * EmbeddingIndexState pada AiEmbeddingActivationCompatibilityGuard::decide().
 * Selama belum ada barrier penulis bersama, layanan ini TIDAK PERNAH
 * mengembalikan ProvenEmpty: keadaan yang tampak kosong dilaporkan Ambiguous
 * dengan alasan indexing_activity_uncoordinated, sehingga cabang
 * REQUIRE_FIRST_SPACE_BOOTSTRAP guard tetap tidak dapat dijangkau.
 *
 * Batas koordinasi (invarian yang masih hilang):
 * - Satu-satunya penulis indeks selalu memerlukan baris knowledge_documents yang
 *   sudah ter-commit sebelum Qdrant disentuh, dan tidak ada jalur kode yang
 *   menghapus baris itu. Karena itu registry kosong yang stabil di antara dua
 *   pembacaan yang mengapit count Qdrant membuktikan tidak ada penulis indeks
 *   pada JENDELA PENGAMATAN.
 * - Yang tidak dapat dibuktikan: tidak ada barrier/epoch ingestion bersama, jadi
 *   penulis baru dapat mulai SETELAH pengamatan berakhir dan sebelum perubahan
 *   konfigurasi di-commit. Tanpa invarian itu klaim kosong lebih lemah daripada
 *   risiko yang hendak dicegah, sehingga wajib dilaporkan Ambiguous.
 *
 * Kode alasan stabil (bagian kontrak, bukan pesan UI):
 *
 * PROVEN_EMPTY:
 * - proven_empty                  (BELUM dipancarkan layanan ini; hanya sah
 *                                  setelah barrier penulis bersama ada dan
 *                                  dipegang oleh pemanggil)
 *
 * POPULATED:
 * - indexed_points_present        (collection berisi titik; termasuk titik lama
 *                                  dari ruang vektor lain yang tidak ikut
 *                                  terhitung oleh filter ruang aktif)
 *
 * AMBIGUOUS:
 * - sql_unavailable               (pembacaan registry awal atau recheck gagal)
 * - qdrant_unavailable            (koneksi/HTTP/konfigurasi Qdrant gagal)
 * - qdrant_access_denied          (HTTP Qdrant menolak akses dengan 401/403)
 * - qdrant_response_invalid       (respons Qdrant rusak atau melanggar protokol)
 * - qdrant_collection_unsupported (konfigurasi collection tidak didukung)
 * - qdrant_collection_missing     (collection terkonfigurasi tidak ditemukan;
 *                                  bukan bukti tidak pernah ada indeks)
 * - observation_unstable          (keadaan SQL berubah di tengah pengamatan)
 * - indexing_activity_unresolved  (dokumen uploaded/processing masih berjalan)
 * - indexing_activity_uncoordinated (tampak kosong, tetapi tidak ada barrier
 *                                  yang mencegah penulis baru muncul)
 * - unknown_document_status       (registry memuat status yang tidak dikenal)
 * - index_metadata_inconsistent   (metadata indexing bertentangan)
 * - unresolved_failed_documents   (dokumen gagal; jejak titiknya tidak pasti)
 * - index_points_missing          (metadata menyatakan terindeks, titik tidak ada)
 *
 * `indexedPointCount` bernilai `0` hanya setelah count eksak berhasil
 * mengembalikan nol; `null` berarti count eksak tidak tersedia, termasuk
 * collection yang tidak ditemukan. `collectionPresent` membedakan collection
 * yang terkonfirmasi tidak ada (`false`) dari collection yang ada dengan nol
 * titik (`true`). Hasil tidak pernah membawa teks/pesan provider, sehingga
 * tidak ada rahasia (endpoint, api-key, isi respons) yang dapat bocor.
 */
final readonly class KnowledgeIndexReadinessResult
{
    public function __construct(
        public EmbeddingIndexState $state,
        public string $reasonCode,
        public ?int $documentCount,
        public ?int $unresolvedIndexingCount,
        public ?int $failedDocumentCount,
        public ?int $indexedPointCount,
        public ?bool $collectionPresent,
    ) {}
}
