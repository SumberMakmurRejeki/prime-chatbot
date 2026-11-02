<?php

namespace App\Services\AI\DocumentIngestion;

use RuntimeException;

/**
 * Kegagalan ingestion PDF yang fail-closed.
 *
 * Pesan exception sengaja tidak memuat path lokal, argumen perintah, atau
 * keluaran mentah proses karena ingestion bisa dipicu dari alur yang lebih
 * tinggi. Pemanggil hanya boleh tahu kategori kegagalannya.
 */
final class PdfIngestionException extends RuntimeException
{
    public static function intakeRejected(): self
    {
        return new self('Sumber PDF ditolak: berkas harus berupa berkas lokal berformat .pdf yang dapat dibaca.');
    }

    public static function sourceTooLarge(): self
    {
        return new self('Sumber PDF melebihi batas ukuran yang diizinkan.');
    }

    public static function unsupportedContent(): self
    {
        return new self('Konten PDF tidak didukung: dokumen terenkripsi, rusak, atau tidak memiliki lapisan teks.');
    }

    public static function pageLimitExceeded(): self
    {
        return new self('Jumlah halaman PDF melebihi batas yang diizinkan.');
    }

    public static function pageTextTooLarge(): self
    {
        return new self('Teks hasil ekstraksi per halaman melebihi batas yang diizinkan.');
    }

    public static function extractedTextTooLarge(): self
    {
        return new self('Total teks hasil ekstraksi melebihi batas yang diizinkan.');
    }

    public static function extractionTimeout(): self
    {
        return new self('Ekstraksi PDF melewati batas waktu yang diizinkan.');
    }

    public static function extractionFailed(): self
    {
        return new self('Ekstraksi PDF gagal dijalankan oleh extractor yang dikonfigurasi.');
    }

    public static function invalidUtf8(): self
    {
        return new self('Teks hasil ekstraksi bukan UTF-8 yang valid.');
    }

    public static function configurationRejected(): self
    {
        return new self('Konfigurasi document ingestion tidak valid.');
    }

    public static function chunkLimitExceeded(): self
    {
        return new self('Chunk tidak dapat dipotong di dalam batas keras grapheme/byte.');
    }
}
