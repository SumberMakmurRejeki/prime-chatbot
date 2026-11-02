<?php

namespace App\Services\AI;

/**
 * Tiga hasil pemeriksaan kompatibilitas ruang embedding.
 *
 * UNKNOWN bukan versi lemah dari INCOMPATIBLE: ia berarti bukti belum memadai,
 * sehingga pemanggil wajib fail-closed dan tidak boleh memperlakukan dua ruang
 * sebagai dapat dipakai bersama. Hanya COMPATIBLE yang mengizinkan pemakaian
 * ulang ruang vektor, dan hanya jaringan pemanggil berikutnya yang berhak
 * memutuskan hal itu.
 */
enum EmbeddingCompatibilityStatus: string
{
    case Compatible = 'COMPATIBLE';

    case Incompatible = 'INCOMPATIBLE';

    case Unknown = 'UNKNOWN';
}
