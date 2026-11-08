<?php

namespace App\Services\AI;

/**
 * Status konfigurasi store Gemini File Search tanpa memanggil Google.
 *
 * Status ini hanya menyatakan apakah store tujuan sudah ditetapkan dan
 * identitasnya valid. Akses nyata ke store dibuktikan terpisah oleh
 * {@see GeminiStoreReadinessState} melalui verifikasi eksplisit.
 */
enum FileSearchConfigurationState: string
{
    case Configured = 'configured';
    case NotConfigured = 'not_configured';

    public function message(): string
    {
        return match ($this) {
            self::Configured => 'Store Gemini File Search dikonfigurasi. Akses belum diperiksa.',
            self::NotConfigured => 'Store Gemini File Search belum dikonfigurasi atau identitasnya tidak valid.',
        };
    }
}
