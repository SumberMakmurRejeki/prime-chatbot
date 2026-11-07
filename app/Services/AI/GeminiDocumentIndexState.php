<?php

namespace App\Services\AI;

/**
 * Status indeksasi satu dokumen Knowledge Base di Gemini File Search.
 *
 * Enum ini milik state yang dipersistenkan PRIME dan sengaja berbeda dari
 * {@see GeminiIndexingState} yang hanya mengklasifikasikan satu respons operasi
 * provider. Ketiadaan baris indeksasi berarti "belum dimulai"; setiap status di
 * sini hanya boleh dicapai bila bukti yang menyertainya sudah ada.
 */
enum GeminiDocumentIndexState: string
{
    /** Sudah dijadwalkan, belum ada permintaan keluar. */
    case Queued = 'queued';

    /** Percobaan unggah sedang berjalan; berhenti di status ini berarti hasil di Google tidak diketahui. */
    case Uploading = 'uploading';

    /** Berkas terunggah dan google_file_id tersimpan; impor belum diminta. */
    case Uploaded = 'uploaded';

    /** Permintaan impor sedang berjalan atau mungkin sudah terkirim tanpa identitas operasi tersimpan. */
    case Importing = 'importing';

    /** Operasi impor diterima Google; identitas operasi tersimpan dan tinggal diperiksa. */
    case Pending = 'pending';

    /** Google menyatakan selesai dan identitas dokumen terverifikasi pada store tujuan. */
    case Completed = 'completed';

    /** Kegagalan pasti: tidak ada permintaan yang terkirim, atau provider menyatakan operasi gagal. */
    case Failed = 'failed';

    /** Hasil di sisi Google tidak dapat dipastikan; operasi non-idempoten tidak boleh diulang otomatis. */
    case Unknown = 'unknown';
}
