<?php

namespace App\Services\AI;

/**
 * Kategori hasil Test Connection yang diizinkan. Nilai ini satu-satunya data
 * diagnostik yang boleh disimpan, ditampilkan, atau dicatat di audit; pesan
 * mentah provider tidak pernah dipakai karena bisa memuat kredensial.
 */
enum ProviderTestCategory: string
{
    case Success = 'success';
    case AuthenticationRejected = 'authentication_rejected';
    case ModelUnavailable = 'model_unavailable';
    case RateLimited = 'rate_limited';
    case QuotaOrCreditExhausted = 'quota_or_credit_exhausted';
    case Timeout = 'timeout';
    case ProviderUnavailable = 'provider_unavailable';
    case UnsupportedConfiguration = 'unsupported_configuration';
    case UnsafeBaseUrl = 'unsafe_base_url';
    case RedirectRejected = 'redirect_rejected';
    case MalformedResponse = 'malformed_response';
    case GenerationRejected = 'generation_rejected';
    case ProviderRequestRejected = 'provider_request_rejected';

    public function passed(): bool
    {
        return $this === self::Success;
    }

    /**
     * Keterangan aman untuk UI. Tidak memuat pesan provider mentah.
     */
    public function safeLabel(): string
    {
        return match ($this) {
            self::Success => 'Uji koneksi berhasil.',
            self::AuthenticationRejected => 'Kredensial ditolak oleh provider.',
            self::ModelUnavailable => 'Model yang diminta tidak tersedia.',
            self::RateLimited => 'Provider membatasi laju permintaan.',
            self::QuotaOrCreditExhausted => 'Kuota atau kredit provider habis.',
            self::Timeout => 'Koneksi ke provider melewati batas waktu.',
            self::ProviderUnavailable => 'Provider tidak dapat dijangkau atau sedang bermasalah.',
            self::UnsupportedConfiguration => 'Kombinasi driver dan tujuan ini tidak didukung.',
            self::UnsafeBaseUrl => 'Base URL ditolak oleh kebijakan endpoint aman.',
            self::RedirectRejected => 'Provider mencoba mengalihkan permintaan; pengalihan ditolak.',
            self::MalformedResponse => 'Respons provider tidak valid atau melebihi batas aman.',
            self::GenerationRejected => 'Provider tidak menghasilkan keluaran yang dapat dipakai.',
            self::ProviderRequestRejected => 'Provider menolak permintaan.',
        };
    }
}
