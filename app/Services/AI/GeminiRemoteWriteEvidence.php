<?php

namespace App\Services\AI;

/**
 * Bukti per generasi apakah satu mutasi Google (unggah/impor) mungkin sudah dimulai.
 *
 * NULL sengaja dilestarikan sebagai nilai historis: baris yang dibuat sebelum
 * kolom ini ada tidak boleh dianggap {@see NotStarted}, dan nilai ini tidak
 * pernah diturunkan dari error code atau timestamp. Satu generasi hanya boleh
 * bergerak dari NotStarted ke MayHaveStarted, tidak pernah sebaliknya.
 */
enum GeminiRemoteWriteEvidence: string
{
    /** Diinisialisasi eksplisit untuk generasi baru; belum ada permintaan tulis. */
    case NotStarted = 'not_started';

    /** Klaim `uploading`/`importing` sudah terjadi; unggah atau impor mungkin sudah terkirim. */
    case MayHaveStarted = 'may_have_started';
}
