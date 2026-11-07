<?php

namespace App\Services\AI;

/**
 * Keputusan tindak lanjut satu eksekusi job indeksasi Gemini.
 *
 * Dipisahkan dari state baris supaya kebijakan "kapan job kembali ke antrean"
 * berada di satu tempat: hanya pemeriksaan yang idempoten (GET status operasi)
 * yang boleh diulang otomatis; unggah dan impor tidak pernah diulang di sini.
 */
enum GeminiIndexingContinuation: string
{
    /** Tahap selesai atau berhenti dengan aman; job boleh berakhir. */
    case Settled = 'settled';

    /** Operasi remote masih berjalan; periksa ulang setelah jeda BERIKUTNYA. */
    case AwaitRemoteCompletion = 'await_remote_completion';

    /** Pembacaan status gagal (idempoten); ulangi pemeriksaan setelah jeda RETRY. */
    case RetryStatusRead = 'retry_status_read';

    public function releaseDelay(): int
    {
        return match ($this) {
            self::AwaitRemoteCompletion => 30,
            self::RetryStatusRead => 60,
            self::Settled => 0,
        };
    }
}
