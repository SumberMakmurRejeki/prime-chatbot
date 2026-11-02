<?php

namespace App\Services\AI;

/**
 * Klasifikasi keadaan pengetahuan/indeks yang disuplai pemanggil guard.
 *
 * Enum ini BUKAN bukti keadaan SQL/Qdrant sendiri: klasifikasi diisi oleh
 * pemanggil guard. KnowledgeIndexReadinessService saat ini tidak pernah
 * mengembalikan ProvenEmpty karena belum ada barrier ingestion bersama.
 * Keadaan kosong yang tampak tetap Ambiguous; ProvenEmpty hanya boleh dipakai
 * pemanggil yang memegang bukti/barrier tambahan. Keadaan meragukan wajib
 * dilaporkan Ambiguous agar guard gagal tertutup dan tidak pernah menganggap
 * bootstrap aman dari hitungan kosong Qdrant.
 */
enum EmbeddingIndexState: string
{
    /**
     * Pemanggil memiliki bukti/barrier tambahan; bukan dihasilkan layanan
     * readiness read-only saat ini.
     */
    case ProvenEmpty = 'PROVEN_EMPTY';

    /** Ada pengetahuan/titik terindeks atau metadata yang tidak diragukan. */
    case Populated = 'POPULATED';

    /** Keadaan tidak diketahui atau tidak konsisten; selalu gagal tertutup. */
    case Ambiguous = 'AMBIGUOUS';
}
