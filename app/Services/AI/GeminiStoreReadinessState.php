<?php

namespace App\Services\AI;

enum GeminiStoreReadinessState: string
{
    case InvalidResponse = 'invalid_response';
    case Accessible = 'accessible';
    case NotConfigured = 'not_configured';
    case AccessDenied = 'access_denied';
    case MissingStore = 'missing_store';
    case Unavailable = 'unavailable';

    /**
     * Keterangan aman untuk UI. Tidak memuat teks, endpoint, atau kredensial
     * provider; kode alasan tetap tersedia terpisah pada DTO kesiapan.
     */
    public function safeMessage(): string
    {
        return match ($this) {
            self::Accessible => 'Akses ke store Gemini File Search terverifikasi.',
            self::NotConfigured => 'Kredensial chat aktif atau store Gemini File Search belum dikonfigurasi.',
            self::AccessDenied => 'Akses ke penyimpanan dokumen ditolak. Periksa API key dan izin Google.',
            self::MissingStore => 'Store Gemini File Search tidak ditemukan.',
            self::Unavailable => 'Store Gemini File Search tidak dapat dijangkau saat ini.',
            self::InvalidResponse => 'Respons Google tidak sesuai kontrak sehingga identitas store tidak terverifikasi.',
        };
    }
}
