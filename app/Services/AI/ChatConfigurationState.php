<?php

namespace App\Services\AI;

/**
 * Status konfigurasi chatbot berdasarkan konfigurasi lokal saja.
 *
 * Status ini tidak pernah berarti koneksi sehat: kelayakan di sini hanya
 * menyatakan revisi chat aktif sudah lengkap dan lulus uji koneksi yang pernah
 * dijalankan, bukan bahwa provider dapat dijangkau saat status dibaca.
 */
enum ChatConfigurationState: string
{
    case Configured = 'configured';
    case NotConfigured = 'not_configured';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';

    public function message(): string
    {
        return match ($this) {
            self::Configured => 'Konfigurasi chatbot tersedia. Koneksi belum diperiksa.',
            self::NotConfigured => 'Konfigurasi chatbot belum lengkap atau belum lulus uji koneksi.',
            self::Unsupported => 'Driver konfigurasi chatbot tidak didukung.',
            self::Unknown => 'Konfigurasi chatbot aktif tidak tunggal sehingga status belum dapat dipastikan.',
        };
    }
}
