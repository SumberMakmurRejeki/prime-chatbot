<?php

namespace App\Services\AI;

/**
 * Ringkasan agregat indeksasi Gemini File Search berdasarkan bukti tersimpan.
 *
 * Agregat hanya mencerminkan baris milik store yang terkonfigurasi; jumlah
 * baris store lain atau tanpa identitas store tetap terlihat pada store_scope.
 * Agregat ini hanya membaca tabel `knowledge_document_gemini_indexings` dan
 * memilih state yang paling membutuhkan tindakan: hasil tak diketahui
 * (menunggu keputusan operator) lebih dulu daripada kegagalan pasti, kemudian
 * proses berjalan, keberhasilan, dan baris yang belum dijadwalkan. Jumlah per
 * kategori tetap dikirim agar state ringkasan tidak menelan detail.
 */
enum GeminiIndexingAggregateState: string
{
    case Disabled = 'disabled';
    case NoDocuments = 'no_documents';
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function message(): string
    {
        return match ($this) {
            self::Disabled => 'Indeksasi Gemini File Search dinonaktifkan.',
            self::NoDocuments => 'Belum ada dokumen knowledge untuk diindeks.',
            self::NotStarted => 'Indeksasi Gemini File Search belum dimulai untuk seluruh dokumen.',
            self::InProgress => 'Sebagian dokumen masih dalam proses indeksasi.',
            self::Completed => 'Seluruh dokumen telah selesai diindeks; status publikasi dan kesiapan jawaban Assistant belum dinyatakan siap.',
            self::Partial => 'Sebagian dokumen selesai diindeks, tetapi masih ada dokumen yang belum dijadwalkan.',
            self::Failed => 'Sebagian dokumen gagal diindeks.',
            self::Unknown => 'Hasil sebagian operasi indeksasi di Google belum diketahui sehingga menunggu pemeriksaan manual.',
        };
    }
}
