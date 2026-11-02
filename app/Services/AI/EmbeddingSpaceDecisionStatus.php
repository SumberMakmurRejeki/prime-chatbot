<?php

namespace App\Services\AI;

/**
 * Jenis keputusan ruang vektor untuk kandidat Activate/Restore embedding.
 *
 * Nilai bukan turunan langsung dari EmbeddingCompatibilityStatus: guard
 * menambahkan dimensi yang tidak dimiliki evaluator, yaitu ruang vektor yang
 * benar-benar terindeks dan kondisi bootstrap tahap pertama. REJECT_REUSE dan
 * REQUIRE_FIRST_SPACE_BOOTSTRAP sama-sama tidak mengizinkan aktivasi; keduanya
 * menuntut keputusan lanjutan yang berbeda (penolakan final vs pembuktian
 * keadaan kosong SQL/Qdrant yang akan diimplementasikan terpisah).
 */
enum EmbeddingSpaceDecisionStatus: string
{
    /** Ruang terindeks yang ada boleh dipakai ulang tanpa re-indexing. */
    case ReuseIndexedSpace = 'REUSE_INDEXED_SPACE';

    /** Pemakaian ulang ditolak; settings provider aktif wajib tetap tidak berubah. */
    case RejectReuse = 'REJECT_REUSE';

    /** Tidak ada ruang terindeks yang layak dipakai ulang; keputusan bootstrap tahap pertama harus diambil secara terpisah. Bukan otorisasi aktivasi. */
    case RequireFirstSpaceBootstrap = 'REQUIRE_FIRST_SPACE_BOOTSTRAP';
}
