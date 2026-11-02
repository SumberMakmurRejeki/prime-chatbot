<?php

namespace App\Services\AI;

use App\Models\KnowledgeDocument;
use App\Services\AI\VectorStore\Qdrant\QdrantCollectionInspector;
use App\Services\AI\VectorStore\VectorStoreError;
use App\Services\AI\VectorStore\VectorStoreException;
use RuntimeException;
use Throwable;

/**
 * Penilaian kesiapan pengetahuan/indeks yang read-only untuk Slice 2B-1.
 *
 * Tujuan: memberi bukti keadaan SQL (registry KnowledgeDocument) dan Qdrant
 * (keberadaan collection + jumlah titik eksak) bagi integrasi Activate/Restore
 * berikutnya. Layanan ini TIDAK mengaktifkan provider, tidak memindahkan
 * pointer revisi, tidak menulis pemetaan ruang vektor, tidak memanggil provider
 * embedding, dan tidak membuat/mengubah/menghapus collection maupun titik
 * Qdrant.
 *
 * Hasilnya hanya field diagnostik terbatas dan kode alasan stabil; pesan
 * exception, endpoint, api-key, dan isi respons mentah provider tidak pernah
 * disalin ke dalam hasil.
 *
 * Prinsip klasifikasi: salah menganggap kosong jauh lebih berbahaya daripada
 * mengaku tidak tahu. Karena itu setiap bukti yang tidak lengkap, bertentangan,
 * atau tidak terbaca menghasilkan AMBIGUOUS, bukan PROVEN_EMPTY.
 *
 * Apa yang dapat dibuktikan pengamatan ini:
 * - Satu-satunya penulis indeks adalah DocumentEmbeddingIndexingService lewat
 *   KnowledgeDocumentService::process(), yang selalu berjalan untuk satu baris
 *   registry yang SUDAH ter-commit sebelum Qdrant disentuh (kontrak visibilitas
 *   commit ingestion).
 * - Tidak ada jalur kode yang menghapus baris knowledge_documents, sehingga
 *   baris registry tidak bisa hilang selagi titiknya tertinggal.
 * - ensureCollection() dijalankan tepat sebelum upsert pertama, jadi aplikasi
 *   ini belum pernah menulis titik ke collection yang SAAT PENGAMATAN tidak
 *   ditemukan. Ini inferensi satu arah: ketiadaan collection tidak membuktikan
 *   belum pernah ada indeks, tidak membuktikan collection lain kosong, dan
 *   tidak membuktikan ingestion tidak akan dimulai (penghapusan di luar jalur
 *   kode aplikasi tidak dapat disingkirkan).
 * - Registry dibaca dua kali mengapit pembacaan Qdrant; perubahan di tengah
 *   pengamatan dilaporkan observation_unstable.
 *
 * Yang TIDAK dapat dibuktikan tanpa perubahan di luar lingkup: tidak ada
 * barrier/epoch ingestion bersama. Penulis baru masih dapat mulai setelah
 * pengamatan berakhir dan sebelum perubahan konfigurasi di-commit, sehingga
 * keadaan yang tampak kosong tetap dilaporkan Ambiguous dengan alasan
 * indexing_activity_uncoordinated. Layanan ini karena itu tidak pernah
 * mengembalikan ProvenEmpty: hasilnya bukan snapshot atomik, bukan reservasi,
 * dan bukan otorisasi.
 */
final class KnowledgeIndexReadinessService
{
    public function __construct(private readonly QdrantCollectionInspector $collection) {}

    public function assess(): KnowledgeIndexReadinessResult
    {
        try {
            $registry = $this->registrySnapshot();
        } catch (Throwable) {
            // Kegagalan query tidak pernah boleh menjadi "kosong".
            return $this->ambiguous('sql_unavailable');
        }

        try {
            $snapshot = $this->collection->inspectCollection();
        } catch (VectorStoreException $exception) {
            return $this->ambiguous($this->qdrantReasonFor($exception), $registry);
        } catch (Throwable) {
            // Perilaku transport tak terduga tetap gagal tertutup.
            return $this->ambiguous('qdrant_unavailable', $registry);
        }

        // Collection yang tidak ditemukan tidak memberi count eksak (null),
        // tetapi ketiadaan itu TIDAK dipakai sebagai bukti kosong: collection
        // dapat dihapus di luar jalur kode. Bila registry juga tidak berisi
        // satu pun dokumen, ketiadaan menjadi alasan ambigu tersendiri; bila
        // registry berisi metadata, alasan inkonsistensi yang lebih spesifik
        // (mis. index_points_missing) yang menang, dengan jumlah titik tetap
        // null karena count eksak tidak pernah terbaca.
        if (! $snapshot->exists && $registry['document_count'] === 0) {
            return $this->ambiguous('qdrant_collection_missing', $registry, false);
        }

        // null = tidak ada count eksak yang dapat dipercaya (collection tidak
        // ditemukan); 0 hanya sah sebagai hasil count eksak yang BERHASIL
        // dibaca dan bernilai nol. Ketiadaan collection tidak boleh disandikan
        // sebagai jumlah titik.
        $pointCount = $snapshot->exactPointCount;

        if ($registry['document_count'] === 0) {
            // Klaim kosong/populated-hanya-titik hanya sah bila keadaan SQL
            // stabil di sekitar pembacaan Qdrant; perubahan registry di tengah
            // pengamatan berarti ada penulis yang tidak dapat dikoordinasikan.
            try {
                $recheck = $this->registrySnapshot();
            } catch (Throwable) {
                return $this->ambiguous('sql_unavailable', $registry, $snapshot->exists, $pointCount);
            }

            if ($recheck !== $registry) {
                return $this->ambiguous('observation_unstable', $recheck, $snapshot->exists, $pointCount);
            }
        }

        return $this->classify($registry, $pointCount, $snapshot->exists);
    }

    /**
     * @param  array<string, int>  $registry
     */
    private function classify(array $registry, ?int $pointCount, bool $collectionPresent): KnowledgeIndexReadinessResult
    {
        // Urutan sengaja: keadaan yang tidak dapat dipercaya diperiksa lebih dulu
        // agar titik yang ada pun tidak dilaporkan sebagai keadaan yang stabil.
        if ($registry['unknown_status_count'] > 0) {
            return $this->ambiguous('unknown_document_status', $registry, $collectionPresent, $pointCount);
        }

        if ($registry['unresolved_indexing_count'] > 0) {
            // Antrian/proses berjalan: penulis dapat menulis titik kapan saja,
            // dan status 'processing' belum tentu sudah menulis Qdrant.
            return $this->ambiguous('indexing_activity_unresolved', $registry, $collectionPresent, $pointCount);
        }

        if ($registry['inconsistent_metadata_count'] > 0) {
            // Misalnya READY tanpa provenance ruang, atau non-READY yang mengklaim
            // provenance/publikasi; metadata seperti ini tidak bisa dipakai
            // sebagai bukti keadaan indeks.
            return $this->ambiguous('index_metadata_inconsistent', $registry, $collectionPresent, $pointCount);
        }

        if ($pointCount !== null && $pointCount > 0) {
            // Bukti terkuat bahwa indeks tidak kosong: jumlah eksak SELURUH
            // collection tanpa filter ruang vektor. Titik milik ruang lain
            // (mis. FNC-01 lama) tetap dihitung.
            return $this->populated('indexed_points_present', $registry, $collectionPresent, $pointCount);
        }

        if ($registry['failed_document_count'] > 0) {
            // Pemrosesan gagal bisa meninggalkan titik, dan dokumen dapat
            // diproses ulang kapan saja; keadaan indeks tidak dapat dipastikan
            // konsisten.
            return $this->ambiguous('unresolved_failed_documents', $registry, $collectionPresent, $pointCount);
        }

        if ($registry['attested_document_count'] > 0) {
            // Metadata menyatakan ruang vektor sudah terindeks, sementara exact
            // count tidak menunjukkan satu titik pun: nol yang terukur, atau
            // collection tidak ditemukan (count null). Keduanya berarti titik
            // yang diklaim metadata tidak terverifikasi ada: SQL dan Qdrant
            // bertentangan.
            return $this->ambiguous('index_points_missing', $registry, $collectionPresent, $pointCount);
        }

        // Registry stabil dan kosong, exact count collection terbaca nol,
        // skema didukung. Keadaan kosong tetap TIDAK dilaporkan PROVEN_EMPTY:
        // tanpa barrier penulis bersama, ingestion baru masih dapat mulai
        // setelah pengamatan ini berakhir dan sebelum perubahan konfigurasi
        // di-commit. Diagnostik (jumlah dokumen, jumlah titik, keberadaan
        // collection) tetap dikembalikan agar dapat dipakai integrasi yang
        // kelak memegang barrier.
        return $this->ambiguous('indexing_activity_uncoordinated', $registry, $collectionPresent, $pointCount);
    }

    /**
     * @param  array<string, int>  $registry
     */
    private function populated(
        string $reasonCode,
        array $registry,
        bool $collectionPresent,
        int $pointCount,
    ): KnowledgeIndexReadinessResult {
        return new KnowledgeIndexReadinessResult(
            state: EmbeddingIndexState::Populated,
            reasonCode: $reasonCode,
            documentCount: $registry['document_count'],
            unresolvedIndexingCount: $registry['unresolved_indexing_count'],
            failedDocumentCount: $registry['failed_document_count'],
            indexedPointCount: $pointCount,
            collectionPresent: $collectionPresent,
        );
    }

    /**
     * Membaca agregat registry dalam satu query, tanpa memuat baris dokumen dan
     * tanpa transaksi/kunci row-lock (lock tidak boleh dihitung melintasi
     * permintaan Qdrant).
     *
     * Semua baris dihitung, bukan hanya dokumen Published: dokumen unpublished,
     * queued, processing, atau gagal juga menentukan keamanan ruang terindeks.
     *
     * @return array{document_count: int, unresolved_indexing_count: int, failed_document_count: int, unknown_status_count: int, inconsistent_metadata_count: int, attested_document_count: int}
     */
    private function registrySnapshot(): array
    {
        $row = KnowledgeDocument::query()->toBase()
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('uploaded', 'processing') THEN 1 ELSE 0 END), 0) as unresolved_indexing_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed_document_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status NOT IN ('uploaded', 'processing', 'ready', 'failed') THEN 1 ELSE 0 END), 0) as unknown_status_count")
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN (status = 'ready' AND vector_space_identity IS NULL)"
                ." OR (status <> 'ready' AND vector_space_identity IS NOT NULL)"
                ." OR (is_published = 1 AND status <> 'ready') THEN 1 ELSE 0 END), 0) as inconsistent_metadata_count"
            )
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'ready' AND vector_space_identity IS NOT NULL THEN 1 ELSE 0 END), 0) as attested_document_count")
            ->first();

        if ($row === null) {
            throw new RuntimeException('Agregat registry KnowledgeDocument tidak dapat dibaca.');
        }

        return [
            'document_count' => (int) $row->document_count,
            'unresolved_indexing_count' => (int) $row->unresolved_indexing_count,
            'failed_document_count' => (int) $row->failed_document_count,
            'unknown_status_count' => (int) $row->unknown_status_count,
            'inconsistent_metadata_count' => (int) $row->inconsistent_metadata_count,
            'attested_document_count' => (int) $row->attested_document_count,
        ];
    }

    private function qdrantReasonFor(VectorStoreException $exception): string
    {
        return match ($exception->kind) {
            // Skema collection tidak didukung V1 (mis. multi-vector).
            VectorStoreError::Contract => 'qdrant_collection_unsupported',
            // Respons tidak sesuai kontrak JSON/protokol.
            VectorStoreError::Protocol => 'qdrant_response_invalid',
            // Penolakan akses (401/403) dibedakan dari gangguan
            // ketersediaan: masalah kredensial/perizinan, bukan jaringan.
            // Selain itu (redirect, 5xx, status lain) tetap unavailable.
            VectorStoreError::Request => match ($exception->httpStatus) {
                401, 403 => 'qdrant_access_denied',
                default => 'qdrant_unavailable',
            },
            // Konfigurasi transport, koneksi, timeout, dan kegagalan tak
            // terduga; tanpa bukti kategori yang lebih spesifik.
            default => 'qdrant_unavailable',
        };
    }

    /**
     * @param  array<string, int>|null  $registry
     */
    private function ambiguous(
        string $reasonCode,
        ?array $registry = null,
        ?bool $collectionPresent = null,
        ?int $pointCount = null,
    ): KnowledgeIndexReadinessResult {
        return new KnowledgeIndexReadinessResult(
            state: EmbeddingIndexState::Ambiguous,
            reasonCode: $reasonCode,
            documentCount: $registry['document_count'] ?? null,
            unresolvedIndexingCount: $registry['unresolved_indexing_count'] ?? null,
            failedDocumentCount: $registry['failed_document_count'] ?? null,
            indexedPointCount: $pointCount,
            collectionPresent: $collectionPresent,
        );
    }
}
